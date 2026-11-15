<?php

namespace App\Domain\Cart;

use App\Domain\Promotions\PromotionService;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Models\Cart;
use App\Models\DeliveryZone;
use App\Models\ProductVariant;
use App\Models\Promotion;

/**
 * Devis serveur (source unique des montants) :
 * subtotal = Σ prix_catalogue × qté ; discount = min(remise, subtotal) ; total = subtotal - discount + frais.
 */
class QuoteService
{
    public function __construct(
        private readonly PromotionService $promotions,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  bool  $lock  verrouille variantes et promotion (SELECT ... FOR UPDATE) lors de la confirmation
     */
    public function quote(Cart $cart, ?string $zoneId, string $fulfillment = 'delivery', ?string $promotionCode = null, bool $lock = false): Quote
    {
        $items = $cart->items()->get();
        if ($items->isEmpty()) {
            throw DomainException::unprocessable('cart_empty', 'Votre panier est vide.');
        }

        $variantQuery = ProductVariant::query()->withTrashed()->with(['product' => fn ($q) => $q->withTrashed()->with('category')])
            ->whereIn('id', $items->pluck('variant_id'));
        if ($lock) {
            $variantQuery->lockForUpdate();
        }
        $variants = $variantQuery->get()->keyBy('id');

        $lines = [];
        $issues = [];
        $subtotal = 0;
        $maxPrep = 0;
        foreach ($items as $item) {
            /** @var ProductVariant $variant */
            $variant = $variants[$item->variant_id];
            $sellable = $variant->isSellable();
            $stockOk = $variant->stock_qty === null || $variant->stock_qty >= $item->quantity;
            if (! $sellable) {
                $issues[] = ['code' => 'variant_unavailable', 'variant_id' => $variant->id, 'message' => "« {$variant->product->name} – {$variant->label} » n'est plus disponible."];
            } elseif (! $stockOk) {
                $issues[] = ['code' => 'insufficient_stock', 'variant_id' => $variant->id, 'available' => $variant->stock_qty, 'message' => "Stock insuffisant pour « {$variant->product->name} – {$variant->label} »."];
            }
            $lineTotal = $variant->price_xof * $item->quantity;
            $subtotal += $lineTotal;
            $maxPrep = max($maxPrep, $variant->prep_minutes);
            $lines[] = [
                'item_id' => $item->id,
                'variant' => $variant,
                'quantity' => $item->quantity,
                'unit_price_xof' => $variant->price_xof,
                'line_total_xof' => $lineTotal,
                'available' => $sellable && $stockOk,
            ];
        }

        $zone = null;
        $deliveryFee = 0;
        if ($fulfillment === 'pickup') {
            if (! $this->settings->get('ordering.pickup_enabled', false)) {
                $issues[] = ['code' => 'pickup_disabled', 'message' => 'Le retrait sur place n\'est pas proposé actuellement.'];
            }
        } elseif ($zoneId !== null) {
            $zone = DeliveryZone::query()->find($zoneId);
            if (! $zone || ! $zone->active) {
                $issues[] = ['code' => 'zone_unavailable', 'message' => 'Cette zone n\'est pas desservie actuellement.'];
                $zone = null;
            } else {
                $deliveryFee = $zone->delivery_fee_xof;
                if ($zone->min_order_xof !== null && $subtotal < $zone->min_order_xof) {
                    $issues[] = ['code' => 'min_order_not_reached', 'min_order_xof' => $zone->min_order_xof, 'message' => 'Le montant minimum de commande pour cette zone n\'est pas atteint.'];
                }
            }
        }

        $promotion = null;
        $discount = 0;
        $code = $promotionCode ?? $cart->promotion_code;
        if ($code) {
            try {
                $promotion = $this->promotions->findApplicable($code, $subtotal, $lock);
                $discount = $this->promotions->discountFor($promotion, $subtotal);
            } catch (DomainException $e) {
                $issues[] = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        $leadMinutes = (int) $this->settings->get('ordering.min_lead_minutes', 240);
        $leadMinutes = max($leadMinutes, $maxPrep) + ($zone?->extra_lead_minutes ?? 0);

        return new Quote(
            lines: $lines,
            subtotal: $subtotal,
            discount: $discount,
            deliveryFee: $deliveryFee,
            zone: $zone,
            promotion: $promotion instanceof Promotion ? $promotion : null,
            leadMinutes: $leadMinutes,
            issues: $issues,
            fulfillment: $fulfillment,
        );
    }
}
