<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_reference' => $this->whenLoaded('order', fn () => $this->order->reference),
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'status' => $this->status->value,
            'amount_xof' => $this->amount_xof,
            'currency' => $this->currency,
            'provider_status' => $this->provider_status,
            'failure_reason' => $this->failure_reason,
            'is_simulated' => $this->is_simulated,
            'is_manual' => $this->is_manual,
            'verified_at' => $this->verified_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'last_checked_at' => $this->last_checked_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'provider_event_id' => $e->provider_event_id,
                'event_type' => $e->event_type,
                'processing_status' => $e->processing_status,
                'received_at' => $e->received_at->toIso8601ZuluString(),
                'processed_at' => $e->processed_at?->toIso8601ZuluString(),
            ])),
        ];
    }
}
