<?php

namespace App\Http\Resources;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Refund */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_reference' => $this->whenLoaded('order', fn () => $this->order->reference),
            'payment_id' => $this->payment_id,
            'amount_xof' => $this->amount_xof,
            'status' => $this->status,
            'reason' => $this->reason,
            'provider_ref' => $this->provider_ref,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'requested_by_id' => $this->requested_by,
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
