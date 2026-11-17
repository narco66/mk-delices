<?php

namespace App\Http\Resources;

use App\Domain\Deliveries\AssignmentStatus;
use App\Domain\Deliveries\DeliveryService;
use App\Models\DeliveryAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mission vue par le livreur affecté. Adresse complète et téléphone dévoilés seulement
 * une fois la mission acceptée et tant qu'elle est en cours ; masqués après clôture (SECURITY §5).
 *
 * @mixin DeliveryAssignment
 */
class MissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->order;
        $inProgress = in_array($this->status, [AssignmentStatus::Accepted, AssignmentStatus::PickedUp], true);
        $address = $order->address_snapshot ?? [];

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'order_reference' => $order->reference,
            'slot_start_at' => $order->slot_start_at?->toIso8601ZuluString(),
            'slot_end_at' => $order->slot_end_at?->toIso8601ZuluString(),
            'zone_name' => $address['zone_name'] ?? null,
            'district' => $address['district'] ?? null,
            'recipient_first_name' => $order->customer_snapshot['first_name'] ?? null,
            'recipient_phone' => $inProgress ? ($order->customer_snapshot['phone'] ?? null) : null,
            'street' => $inProgress ? ($address['street'] ?? null) : null,
            'landmark' => $inProgress ? ($address['landmark'] ?? null) : null,
            'lat' => $inProgress ? ($address['lat'] ?? null) : null,
            'lng' => $inProgress ? ($address['lng'] ?? null) : null,
            'customer_notes' => $inProgress ? $order->customer_notes : null,
            'items' => $order->items->map(fn ($i) => ['name' => $i->product_name_snapshot, 'variant' => $i->variant_label_snapshot, 'qty' => $i->qty, 'storage' => $i->storage_snapshot]),
            'requires_cold_chain' => $order->items->contains(fn ($i) => (bool) $i->product?->requires_cold_chain),
            'cash_to_collect_xof' => $this->cash_to_collect_xof,
            'code_attempts_left' => max(0, DeliveryService::MAX_CODE_ATTEMPTS - (int) $this->code_failed_attempts),
            'partner_fee_xof' => $this->partner_fee_xof,
            'assigned_at' => $this->assigned_at?->toIso8601ZuluString(),
            'accepted_at' => $this->accepted_at?->toIso8601ZuluString(),
            'picked_up_at' => $this->picked_up_at?->toIso8601ZuluString(),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'failed_at' => $this->failed_at?->toIso8601ZuluString(),
            'proof_type' => $this->proof_type,
            'incident_reason' => $this->incident_reason,
            'settled_at' => $this->settled_at?->toIso8601ZuluString(),
            'available_actions' => match ($this->status) {
                AssignmentStatus::Proposed => ['accept', 'refuse'],
                AssignmentStatus::Accepted => ['pick_up', 'release'],
                AssignmentStatus::PickedUp => ['deliver', 'fail'],
                default => [],
            },
        ];
    }
}
