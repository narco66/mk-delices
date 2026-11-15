<?php

namespace App\Http\Resources;

use App\Models\DeliveryAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeliveryAssignment */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_reference' => $this->whenLoaded('order', fn () => $this->order->reference),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'partner' => $this->whenLoaded('partner', fn () => ['id' => $this->partner->id, 'name' => $this->partner->display_name, 'transport_mode' => $this->partner->transport_mode]),
            'partner_fee_xof' => $this->partner_fee_xof,
            'cash_to_collect_xof' => $this->cash_to_collect_xof,
            'cash_collected_xof' => $this->cash_collected_xof,
            'assigned_at' => $this->assigned_at?->toIso8601ZuluString(),
            'accepted_at' => $this->accepted_at?->toIso8601ZuluString(),
            'refused_at' => $this->refused_at?->toIso8601ZuluString(),
            'refusal_reason' => $this->refusal_reason,
            'picked_up_at' => $this->picked_up_at?->toIso8601ZuluString(),
            'packaging_checked' => $this->packaging_checked,
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'proof_type' => $this->proof_type,
            'proof_reference' => $this->proof_reference,
            'failed_at' => $this->failed_at?->toIso8601ZuluString(),
            'incident_reason' => $this->incident_reason,
            'incident_note' => $this->incident_note,
            'product_condition' => $this->product_condition,
            'contact_attempted' => $this->contact_attempted,
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'cancel_reason' => $this->cancel_reason,
            'settled_at' => $this->settled_at?->toIso8601ZuluString(),
        ];
    }
}
