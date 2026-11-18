<?php

namespace App\Domain\Orders;

use App\Domain\Cart\Quote;
use App\Domain\Cart\QuoteService;
use App\Domain\Geo\GeoPoint;
use App\Domain\Geo\ZoneLocator;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Domain\Promotions\PromotionService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Phone;
use App\Domain\Shared\Settings;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Création transactionnelle des commandes (web et brouillons WhatsApp).
 * Montants, zone, créneau et disponibilité sont toujours recalculés côté serveur.
 */
class CheckoutService
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly SlotService $slots,
        private readonly StockService $stock,
        private readonly OrderTracking $tracking,
        private readonly OrderJournal $journal,
        private readonly OrderStateMachine $stateMachine,
        private readonly OrderNotifier $notifier,
        private readonly GatewayRegistry $gateways,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
        private readonly ZoneLocator $zones,
    ) {}

    /**
     * Confirme le panier en commande « pending_confirmation » (ou convertit atomiquement le brouillon WhatsApp du même panier).
     *
     * $staff : commande saisie au back-office (téléphone, boutique) par un membre de l'équipe. Mêmes règles de prix,
     * créneau, stock et promotion que sur le site ; le moyen de paiement peut rester vide (encaissement enregistré
     * ensuite depuis la commande) et le client n'est jamais rattaché au compte de l'employé.
     *
     * @return array{order: Order, tracking_token: string}
     */
    public function placeOrder(Cart $cart, array $data, ?User $user, ?User $staff = null): array
    {
        return DB::transaction(function () use ($cart, $data, $user, $staff) {
            /** @var Cart $cart */
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $existing = $cart->converted_order_id ? Order::query()->lockForUpdate()->find($cart->converted_order_id) : null;
            if ($existing && $existing->status !== OrderStatus::Draft) {
                throw DomainException::conflict('cart_converted', 'Ce panier a déjà donné lieu à une commande.', ['reference' => $existing->reference]);
            }

            $fulfillment = $data['fulfillment_type'] ?? 'delivery';
            $zoneId = $data['delivery']['zone_id'] ?? null;
            $zoneSource = ZoneLocator::SOURCE_DECLARED;
            $point = null;
            if ($fulfillment === 'delivery') {
                // DEC-GEO-02 : avec une position, la zone (donc les frais) est déterminée par le serveur, jamais
                // par le choix du client ; hors des zones dessinées, la commande en ligne est refusée.
                $point = GeoPoint::maybe($data['delivery']['lat'] ?? null, $data['delivery']['lng'] ?? null);
                $located = $this->zones->locateOrFail($point, $zoneId);
                $zoneId = $located['zone']?->id ?? $zoneId;
                $zoneSource = $located['source'];
            }
            $quote = $this->quotes->quote($cart, $zoneId, $fulfillment, $data['promotion_code'] ?? null, lock: true);
            $quote->assertValid();
            // Montant attendu (docs/audit-geolocalisation) : le client valide le montant qu'il a vu ; s'il a changé entre-temps
            // (frais de zone modifiés, zone recalculée), il est prévenu au lieu d'être engagé sur un autre total.
            if (isset($data['expected_total_xof']) && (int) $data['expected_total_xof'] !== $quote->total()) {
                throw DomainException::conflict('total_changed', 'Le montant de votre commande a changé. Vérifiez le nouveau total avant de valider.', [
                    'total_xof' => $quote->total(), 'delivery_fee_xof' => $quote->deliveryFee, 'zone' => $quote->zone ? ['id' => $quote->zone->id, 'name' => $quote->zone->name] : null,
                ]);
            }

            $method = $data['payment_method'] ?? null;
            $available = array_column($this->gateways->availableMethods(), 'code');
            if ($method !== null && ! in_array($method, $available, true)) {
                throw DomainException::unprocessable('payment_method_unavailable', 'Ce moyen de paiement n\'est pas disponible.');
            }
            if ($method === null && $available !== [] && ! $staff) {
                throw DomainException::unprocessable('payment_method_required', 'Veuillez choisir un moyen de paiement.');
            }

            $slot = $this->slots->reserve((string) $data['slot_start_at'], $quote->leadMinutes, $existing?->id);
            $customer = $this->resolveCustomer($data['customer'], $staff ? null : $user, ! $staff && (bool) ($data['marketing_consent'] ?? false));

            $order = $existing ?? new Order(['reference' => OrderReference::generate(), 'channel' => $staff ? ($data['channel'] ?? 'phone') : 'web']);
            $from = $existing?->status->value;
            $order->fill([
                'customer_id' => $customer->id,
                'cart_id' => $cart->id,
                'status' => OrderStatus::PendingConfirmation,
                'payment_status' => OrderPaymentStatus::Unpaid,
                'payment_method' => $method,
                'fulfillment_type' => $fulfillment,
                'zone_id' => $quote->zone?->id,
                'customer_snapshot' => $this->customerSnapshot($data['customer']),
                'address_snapshot' => $fulfillment === 'delivery' ? $this->addressSnapshot($data['delivery'], $quote, $point, $zoneSource) : null,
                'slot_start_at' => $slot['start'],
                'slot_end_at' => $slot['end'],
                'customer_notes' => $data['notes'] ?? null,
                // Commande saisie par l'équipe : le client n'a pas coché les CGV en ligne.
                'terms_accepted_at' => $staff ? null : now(),
                'confirmed_at' => now(),
            ]);
            if ($staff && ! empty($data['internal_notes'])) {
                $order->internal_notes = $data['internal_notes'];
            }
            $this->applyQuote($order, $quote);
            $order->version = ($order->version ?? 0) + 1;
            $token = $this->tracking->issue($order);
            $order->save();

            $this->snapshotItems($order, $quote);
            $this->stock->reserveForOrder($order);
            if ($quote->promotion) {
                Promotion::query()->whereKey($quote->promotion->id)->increment('used_count');
            }
            $cart->update(['converted_order_id' => $order->id, 'customer_id' => $customer->id]);

            $this->journal->record($order, 'status', $staff ?? $user, null, ['action' => $staff ? 'backoffice_entry' : 'checkout', 'channel' => $order->channel], $from, OrderStatus::PendingConfirmation->value);
            if ($staff) {
                $this->audit->log('order.backoffice_created', $order, null, ['reference' => $order->reference, 'channel' => $order->channel, 'total_xof' => $order->total_xof]);
            }
            DB::afterCommit(fn () => $this->notifier->orderStatusChanged($order->fresh(), OrderStatus::PendingConfirmation));

            return ['order' => $order->fresh('items'), 'tracking_token' => $token];
        });
    }

    /**
     * Crée ou met à jour le brouillon WhatsApp du panier. Le brouillon ne réserve ni stock ni créneau
     * et ne vaut ni confirmation ni paiement (BR-007).
     *
     * @return array{order: Order, tracking_token: ?string, created: bool}
     */
    public function saveWhatsAppDraft(Cart $cart, array $data, ?User $user): array
    {
        return DB::transaction(function () use ($cart, $data, $user) {
            /** @var Cart $cart */
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $existing = $cart->converted_order_id ? Order::query()->lockForUpdate()->find($cart->converted_order_id) : null;
            if ($existing && $existing->status !== OrderStatus::Draft) {
                // Anti-doublon : le panier est déjà une commande, on renvoie la même référence.
                return ['order' => $existing->load('items'), 'tracking_token' => null, 'created' => false];
            }
            $quote = $this->quotes->quote($cart, $data['zone_id'] ?? null, 'delivery', $data['promotion_code'] ?? null);
            $blocking = array_filter($quote->issues, fn ($i) => in_array($i['code'], ['variant_unavailable', 'insufficient_stock'], true));
            if ($blocking !== []) {
                $quote->assertValid(false);
            }

            $order = $existing ?? new Order(['reference' => OrderReference::generate(), 'channel' => 'whatsapp', 'status' => OrderStatus::Draft, 'payment_status' => OrderPaymentStatus::Unpaid]);
            $order->fill([
                'cart_id' => $cart->id,
                'fulfillment_type' => 'delivery',
                'zone_id' => $quote->zone?->id,
                'customer_snapshot' => array_filter(['first_name' => $data['first_name'] ?? null]),
            ]);
            $this->applyQuote($order, $quote);
            $order->version = ($order->version ?? 0) + 1;
            $token = $existing ? null : $this->tracking->issue($order);
            $order->save();
            $this->snapshotItems($order, $quote);
            $cart->update(['converted_order_id' => $order->id]);

            if (! $existing) {
                $this->journal->record($order, 'status', $user, null, ['action' => 'whatsapp_draft'], null, OrderStatus::Draft->value);
            }

            return ['order' => $order->fresh('items'), 'tracking_token' => $token, 'created' => ! $existing];
        });
    }

    /** Confirmation commerciale d'un brouillon : re-tarification, créneau, stock, puis transition draft → pending_confirmation. */
    public function confirmDraft(Order $order, User $actor, ?int $expectedVersion = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $expectedVersion) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== OrderStatus::Draft) {
                throw DomainException::conflict('invalid_transition', 'Seul un brouillon peut être confirmé.');
            }
            if (empty($locked->customer_snapshot['phone']) || empty($locked->customer_snapshot['first_name'])) {
                throw DomainException::unprocessable('details_incomplete', 'Renseignez le prénom et le téléphone du destinataire avant confirmation.');
            }
            if ($locked->fulfillment_type === 'delivery' && ($locked->zone_id === null || empty($locked->address_snapshot['district']))) {
                throw DomainException::unprocessable('details_incomplete', 'Renseignez la zone et le quartier de livraison avant confirmation.');
            }
            if ($locked->slot_start_at === null) {
                throw DomainException::unprocessable('details_incomplete', 'Choisissez un créneau avant confirmation.');
            }
            $this->repriceFromCatalog($locked);
            // Même délai de préparation que pour une commande en ligne (BR-004, audit lot 3).
            $this->slots->reserve($locked->slot_start_at->toIso8601ZuluString(), $this->leadMinutesFor($locked), $locked->id);
            if ($locked->customer_id === null) {
                $customer = Customer::query()->create([
                    'first_name' => $locked->customer_snapshot['first_name'],
                    'last_name' => $locked->customer_snapshot['last_name'] ?? null,
                    'phone' => $locked->customer_snapshot['phone'],
                ]);
                $locked->customer_id = $customer->id;
                $locked->save();
            }

            return $this->stateMachine->apply($locked, 'submit', $actor, null, $expectedVersion);
        });
    }

    /**
     * Mise à jour des coordonnées/créneau par le back-office, interdite après passage en préparation (PRD §7).
     */
    public function updateDetails(Order $order, array $data, User $actor): Order
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->details_locked || ! in_array($locked->status, [OrderStatus::Draft, OrderStatus::PendingConfirmation, OrderStatus::Accepted], true)) {
                throw DomainException::conflict('details_locked', 'Les informations de livraison sont figées à ce stade de la commande.');
            }
            if (isset($data['expected_version']) && (int) $data['expected_version'] !== $locked->version) {
                throw DomainException::conflict('version_conflict', 'La commande a été modifiée entre-temps.');
            }
            $before = $locked->only(['customer_snapshot', 'address_snapshot', 'zone_id', 'slot_start_at', 'delivery_fee_xof', 'total_xof']);

            if (isset($data['customer'])) {
                $snapshot = array_merge($locked->customer_snapshot ?? [], $this->customerSnapshot($data['customer']));
                $locked->customer_snapshot = $snapshot;
            }
            if (array_key_exists('delivery', $data) && $data['delivery'] !== null) {
                $zone = DeliveryZone::query()->where('active', true)->find($data['delivery']['zone_id'] ?? null);
                if (! $zone) {
                    throw DomainException::unprocessable('zone_unavailable', 'Zone de livraison inactive ou inconnue.');
                }
                $newFee = $zone->delivery_fee_xof;
                if ($newFee !== $locked->delivery_fee_xof && $locked->paid_xof > 0) {
                    throw DomainException::conflict('amount_change_after_payment', 'Les frais ne peuvent pas changer après encaissement. Annulez et recréez la commande si nécessaire.');
                }
                // Audit M3 : le client paierait l'ancien montant (trop-perçu ou solde dû silencieux).
                if ($newFee !== $locked->delivery_fee_xof && app(PaymentService::class)->hasOpenOnlineSession($locked)) {
                    throw DomainException::conflict('online_payment_in_progress', 'Un paiement en ligne est en cours : les frais ne peuvent pas changer tant qu\'il n\'est pas terminé.');
                }
                $locked->zone_id = $zone->id;
                $previous = $locked->address_snapshot ?? [];
                // Position du client conservée si le commercial ne la transmet pas (sinon elle serait effacée).
                $point = array_key_exists('lat', $data['delivery'])
                    ? GeoPoint::maybe($data['delivery']['lat'], $data['delivery']['lng'] ?? null)
                    : GeoPoint::maybe($previous['lat'] ?? null, $previous['lng'] ?? null);
                $samePoint = $point && $point->lat === ($previous['lat'] ?? null) && $point->lng === ($previous['lng'] ?? null);
                $locked->address_snapshot = [
                    'zone_name' => $zone->name,
                    'district' => $data['delivery']['district'],
                    'street' => $data['delivery']['street'] ?? null,
                    'landmark' => $data['delivery']['landmark'] ?? null,
                    'lat' => $point?->lat,
                    'lng' => $point?->lng,
                    'location_source' => $point ? ($samePoint ? ($previous['location_source'] ?? null) : 'staff') : null,
                    'accuracy_m' => $samePoint ? ($previous['accuracy_m'] ?? null) : null,
                    // Zone choisie par l'équipe : décision humaine, tracée dans l'audit de la commande.
                    'zone_source' => 'staff',
                ];
                $locked->delivery_fee_xof = $newFee;
                $locked->total_xof = $locked->subtotal_xof - $locked->discount_xof + $newFee;
            }
            if (! empty($data['slot_start_at'])) {
                $slot = $locked->status === OrderStatus::Draft
                    ? ['start' => CarbonImmutable::parse($data['slot_start_at']), 'end' => CarbonImmutable::parse($data['slot_start_at'])->addMinutes((int) $this->settings->get('ordering.slot_duration_minutes', 120))]
                    : $this->slots->reserve($data['slot_start_at'], $this->leadMinutesFor($locked), $locked->id);
                $locked->slot_start_at = $slot['start'];
                $locked->slot_end_at = $slot['end'];
            }
            if (array_key_exists('internal_notes', $data)) {
                $locked->internal_notes = $data['internal_notes'];
            }
            $locked->version++;
            $locked->save();
            $this->journal->record($locked, 'details', $actor, $data['reason'] ?? null, ['fields' => array_keys($data)]);
            $this->audit->log('order.details_updated', $locked, $before, $locked->only(array_keys($before)));

            return $locked;
        });
    }

    /**
     * Brouillon confirmé : le code promo est revérifié comme au paiement en ligne (dates, limite d'usage,
     * minimum), la remise recalculée sur les prix du jour et l'usage compté (audit M4). Un code devenu
     * invalide est retiré et la raison tracée dans l'historique de la commande.
     */
    private function revalidateDraftPromotion(Order $order, int $subtotal): int
    {
        if (! $order->promotion_code) {
            return 0;
        }
        $promotions = app(PromotionService::class);
        try {
            $promotion = $promotions->findApplicable($order->promotion_code, $subtotal, lock: true);
        } catch (DomainException $e) {
            $this->journal->record($order, 'details', null, $e->getMessage(), ['event' => 'promotion.removed', 'code' => $order->promotion_code]);
            $order->promotion_id = null;
            $order->promotion_code = null;

            return 0;
        }
        $order->promotion_id = $promotion->id;
        Promotion::query()->whereKey($promotion->id)->increment('used_count');

        return min($promotions->discountFor($promotion, $subtotal), $subtotal);
    }

    /**
     * Délai minimal avant le créneau : délai général, ou temps de préparation du produit le plus long, plus le
     * délai supplémentaire de la zone — exactement comme le devis d'une commande en ligne (QuoteService).
     */
    private function leadMinutesFor(Order $order): int
    {
        $prep = (int) $order->items()->max('prep_minutes_snapshot');
        $extra = $order->zone_id ? (int) DeliveryZone::query()->whereKey($order->zone_id)->value('extra_lead_minutes') : 0;

        return max((int) $this->settings->get('ordering.min_lead_minutes', 240), $prep) + $extra;
    }

    private function repriceFromCatalog(Order $order): void
    {
        $subtotal = 0;
        foreach ($order->items()->get() as $item) {
            $variant = ProductVariant::query()->with('product.category')->lockForUpdate()->find($item->variant_id);
            if (! $variant || ! $variant->isSellable()) {
                throw DomainException::conflict('variant_unavailable', "« {$item->product_name_snapshot} – {$item->variant_label_snapshot} » n'est plus disponible.");
            }
            $item->update([
                'unit_price_xof' => $variant->price_xof,
                'line_total_xof' => $variant->price_xof * $item->qty,
                'allergens_snapshot' => $variant->product->allergens ?? [],
            ]);
            $subtotal += $variant->price_xof * $item->qty;
        }
        $order->subtotal_xof = $subtotal;
        $order->discount_xof = $this->revalidateDraftPromotion($order, $subtotal);
        if ($order->zone_id) {
            $order->delivery_fee_xof = (int) DeliveryZone::query()->whereKey($order->zone_id)->value('delivery_fee_xof');
        }
        $order->total_xof = $order->subtotal_xof - $order->discount_xof + $order->delivery_fee_xof;
        $order->save();
    }

    private function applyQuote(Order $order, Quote $quote): void
    {
        $order->subtotal_xof = $quote->subtotal;
        $order->discount_xof = $quote->discount;
        $order->delivery_fee_xof = $quote->deliveryFee;
        $order->total_xof = $quote->total();
        $order->promotion_id = $quote->promotion?->id;
        $order->promotion_code = $quote->promotion?->code;
        $order->currency = 'XOF';
    }

    private function snapshotItems(Order $order, Quote $quote): void
    {
        $order->items()->delete();
        foreach ($quote->lines as $line) {
            $variant = $line['variant'];
            $order->items()->create([
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_name_snapshot' => $variant->product->name,
                'variant_label_snapshot' => $variant->label,
                'sku_snapshot' => $variant->sku,
                'unit_price_xof' => $line['unit_price_xof'],
                'qty' => $line['quantity'],
                'line_total_xof' => $line['line_total_xof'],
                'allergens_snapshot' => $variant->product->allergens ?? [],
                'storage_snapshot' => $variant->product->storage_instructions,
                'prep_minutes_snapshot' => $variant->prep_minutes,
            ]);
        }
    }

    private function resolveCustomer(array $input, ?User $user, bool $marketingConsent): Customer
    {
        $phone = Phone::normalizeSenegal($input['phone'] ?? null);
        if ($phone === null) {
            throw DomainException::unprocessable('invalid_phone', 'Numéro de téléphone sénégalais invalide.');
        }
        $attributes = [
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'] ?? null,
            'phone' => $phone,
            'email' => $input['email'] ?? null,
        ];
        if ($user && $user->customer) {
            $customer = $user->customer;
        } else {
            // Pas de fusion de clients invités par téléphone : évite toute fuite d'historique entre personnes.
            $customer = Customer::query()->create($attributes + ['user_id' => $user?->id]);
        }
        if ($marketingConsent && $customer->marketing_consent_at === null) {
            $customer->update(['marketing_consent_at' => now()]);
        }

        return $customer;
    }

    private function customerSnapshot(array $input): array
    {
        $phone = isset($input['phone']) ? Phone::normalizeSenegal($input['phone']) : null;
        if (isset($input['phone']) && $phone === null) {
            throw DomainException::unprocessable('invalid_phone', 'Numéro de téléphone sénégalais invalide.');
        }

        return array_filter([
            'first_name' => $input['first_name'] ?? null,
            'last_name' => $input['last_name'] ?? null,
            'phone' => $phone,
            'email' => $input['email'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function addressSnapshot(array $delivery, Quote $quote, ?GeoPoint $point, string $zoneSource): array
    {
        return [
            'zone_name' => $quote->zone?->name,
            'district' => $delivery['district'],
            'street' => $delivery['street'] ?? null,
            'landmark' => $delivery['landmark'] ?? null,
            'lat' => $point?->lat,
            'lng' => $point?->lng,
            // Origine de la position (gps, carte, recherche, adresse enregistrée) et précision annoncée.
            'location_source' => $point ? ($delivery['location_source'] ?? null) : null,
            'accuracy_m' => $point && isset($delivery['accuracy_m']) ? (int) $delivery['accuracy_m'] : null,
            // « position » : zone calculée par le serveur ; « declared » : choisie par le client, à vérifier.
            'zone_source' => $zoneSource,
        ];
    }
}
