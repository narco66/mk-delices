<?php

namespace App\Http\Resources;

use App\Domain\Shared\Phone;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commande vue par le back-office. Les coordonnées complètes ne sont exposées qu'aux rôles
 * qui en ont besoin (orders.view, deliveries.view) — SEC-011.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeContact = $user && ($user->can('orders.view') || $user->can('deliveries.view'));
        $snapshot = $this->customer_snapshot ?? [];

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'channel' => $this->channel,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'payment_method' => $this->payment_method,
            'fulfillment_type' => $this->fulfillment_type,
            'subtotal_xof' => $this->subtotal_xof,
            'discount_xof' => $this->discount_xof,
            'delivery_fee_xof' => $this->delivery_fee_xof,
            'total_xof' => $this->total_xof,
            'paid_xof' => $this->paid_xof,
            'refunded_xof' => $this->refunded_xof,
            'currency' => $this->currency,
            'promotion_code' => $this->promotion_code,
            'customer' => [
                'first_name' => $snapshot['first_name'] ?? null,
                'last_name' => $snapshot['last_name'] ?? null,
                'phone' => $canSeeContact ? ($snapshot['phone'] ?? null) : Phone::mask($snapshot['phone'] ?? null),
                'email' => $canSeeContact ? ($snapshot['email'] ?? null) : null,
            ],
            'customer_id' => $this->customer_id,
            'zone' => $this->whenLoaded('zone', fn () => $this->zone ? ['id' => $this->zone->id, 'name' => $this->zone->name] : null),
            'address' => $canSeeContact ? $this->address_snapshot : ($this->address_snapshot ? ['zone_name' => $this->address_snapshot['zone_name'] ?? null, 'district' => $this->address_snapshot['district'] ?? null] : null),
            'slot_start_at' => $this->slot_start_at?->toIso8601ZuluString(),
            'slot_end_at' => $this->slot_end_at?->toIso8601ZuluString(),
            'customer_notes' => $this->customer_notes,
            'internal_notes' => $this->when($user?->can('orders.view'), $this->internal_notes),
            'details_locked' => $this->details_locked,
            'is_test' => $this->is_test,
            'version' => $this->version,
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601ZuluString(),
                'confirmed_at' => $this->confirmed_at?->toIso8601ZuluString(),
                'accepted_at' => $this->accepted_at?->toIso8601ZuluString(),
                'preparing_at' => $this->preparing_at?->toIso8601ZuluString(),
                'ready_at' => $this->ready_at?->toIso8601ZuluString(),
                'picked_up_at' => $this->picked_up_at?->toIso8601ZuluString(),
                'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
                'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'variant_id' => $i->variant_id,
                'product_name' => $i->product_name_snapshot,
                'variant_label' => $i->variant_label_snapshot,
                'sku' => $i->sku_snapshot,
                'unit_price_xof' => $i->unit_price_xof,
                'qty' => $i->qty,
                'line_total_xof' => $i->line_total_xof,
                'allergens' => $i->allergens_snapshot,
                'storage' => $i->storage_snapshot,
                'prep_minutes' => $i->prep_minutes_snapshot,
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'reason' => $e->reason,
                'metadata' => $e->metadata,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'actor_label' => $e->actor_label,
                'request_id' => $e->request_id,
                'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
            ])),
            'payments' => $this->when($user?->can('payments.view') && $this->relationLoaded('payments'), fn () => PaymentResource::collection($this->payments)),
            'refunds' => $this->when($user?->can('payments.view') && $this->relationLoaded('refunds'), fn () => RefundResource::collection($this->refunds)),
            'assignments' => $this->whenLoaded('assignments', fn () => AssignmentResource::collection($this->assignments)),
        ];
    }
}
