<?php

namespace App\Http\Resources;

use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Suivi invité : étapes non sensibles uniquement (UX-07), aucune donnée de contact complète.
 *
 * @mixin Order
 */
class PublicOrderResource extends JsonResource
{
    private const STEPS = ['pending_confirmation', 'accepted', 'preparing', 'ready', 'picked_up', 'delivered'];

    public function toArray(Request $request): array
    {
        $current = $this->status->value === 'assigned' ? 'ready' : $this->status->value;
        $reached = array_search($current, self::STEPS, true);

        return [
            'reference' => $this->reference,
            'channel' => $this->channel,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'payment_method' => $this->payment_method,
            'fulfillment_type' => $this->fulfillment_type,
            'first_name' => $this->customer_snapshot['first_name'] ?? null,
            'zone_name' => $this->address_snapshot['zone_name'] ?? null,
            'district' => $this->address_snapshot['district'] ?? null,
            'slot_start_at' => $this->slot_start_at?->toIso8601ZuluString(),
            'slot_end_at' => $this->slot_end_at?->toIso8601ZuluString(),
            'subtotal_xof' => $this->subtotal_xof,
            'discount_xof' => $this->discount_xof,
            'delivery_fee_xof' => $this->delivery_fee_xof,
            'total_xof' => $this->total_xof,
            'amount_due_xof' => max(0, $this->total_xof - $this->paid_xof),
            'currency' => $this->currency,
            'items' => $this->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'product_name' => $i->product_name_snapshot,
                'variant_label' => $i->variant_label_snapshot,
                'qty' => $i->qty,
                'unit_price_xof' => $i->unit_price_xof,
                'line_total_xof' => $i->line_total_xof,
                'allergens' => $i->allergens_snapshot,
            ]),
            'timeline' => in_array($this->status, [OrderStatus::Cancelled, OrderStatus::Rejected, OrderStatus::Draft], true) ? [] : array_map(fn ($step, $i) => [
                'status' => $step,
                'label' => OrderStatus::from($step)->label(),
                'done' => $reached !== false && $i <= $reached,
            ], self::STEPS, array_keys(self::STEPS)),
            'can_pay' => in_array($this->status, [OrderStatus::PendingConfirmation, OrderStatus::Accepted], true)
                && in_array($this->payment_status->value, ['unpaid', 'failed', 'pending'], true),
            'can_cancel' => $this->status === OrderStatus::PendingConfirmation,
            'can_review' => $this->status === OrderStatus::Delivered,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
