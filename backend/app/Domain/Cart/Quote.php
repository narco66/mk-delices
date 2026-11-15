<?php

namespace App\Domain\Cart;

use App\Domain\Shared\DomainException;
use App\Models\DeliveryZone;
use App\Models\Promotion;

final class Quote
{
    public function __construct(
        public readonly array $lines,
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly int $deliveryFee,
        public readonly ?DeliveryZone $zone,
        public readonly ?Promotion $promotion,
        public readonly int $leadMinutes,
        public readonly array $issues,
        public readonly string $fulfillment,
    ) {}

    public function total(): int
    {
        return $this->subtotal - $this->discount + $this->deliveryFee;
    }

    public function isValid(): bool
    {
        return $this->issues === [];
    }

    /** Bloque la confirmation si un contrôle métier échoue (BR-001, BR-003). */
    public function assertValid(bool $requireZone = true): void
    {
        if ($requireZone && $this->fulfillment === 'delivery' && $this->zone === null && $this->issues === []) {
            throw DomainException::unprocessable('zone_required', 'Veuillez choisir une zone de livraison desservie.');
        }
        if ($this->issues !== []) {
            $first = $this->issues[0];
            $status = in_array($first['code'], ['variant_unavailable', 'insufficient_stock'], true) ? 409 : 422;

            throw new DomainException($first['code'], $first['message'], $status, ['issues' => $this->issues]);
        }
    }

    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (array $l) => [
                'item_id' => $l['item_id'],
                'variant_id' => $l['variant']->id,
                'product_name' => $l['variant']->product->name,
                'product_slug' => $l['variant']->product->slug,
                'variant_label' => $l['variant']->label,
                'quantity' => $l['quantity'],
                'unit_price_xof' => $l['unit_price_xof'],
                'line_total_xof' => $l['line_total_xof'],
                'available' => $l['available'],
                'allergens' => $l['variant']->product->allergens ?? [],
            ], $this->lines),
            'subtotal_xof' => $this->subtotal,
            'discount_xof' => $this->discount,
            'delivery_fee_xof' => $this->deliveryFee,
            'total_xof' => $this->total(),
            'currency' => 'XOF',
            'fulfillment_type' => $this->fulfillment,
            'zone' => $this->zone ? ['id' => $this->zone->id, 'name' => $this->zone->name] : null,
            'promotion' => $this->promotion ? ['code' => $this->promotion->code, 'name' => $this->promotion->name] : null,
            'lead_minutes' => $this->leadMinutes,
            'issues' => $this->issues,
            'valid' => $this->isValid(),
        ];
    }
}
