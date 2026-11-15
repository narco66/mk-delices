<?php

namespace App\Domain\Orders;

use App\Domain\Shared\RequestContext;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;

/** Écrit l'historique immuable d'une commande : acteur, motif, horodatage UTC, request_id (BR-015). */
class OrderJournal
{
    public function record(Order $order, string $type, ?User $actor, ?string $reason = null, array $metadata = [], ?string $from = null, ?string $to = null): OrderEvent
    {
        return OrderEvent::query()->create([
            'order_id' => $order->id,
            'actor_id' => $actor?->id,
            'actor_label' => $actor ? ($actor->getRoleNames()->first() ?? 'user') : 'system',
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'metadata' => $metadata ?: null,
            'request_id' => RequestContext::id(),
            'occurred_at' => now(),
        ]);
    }
}
