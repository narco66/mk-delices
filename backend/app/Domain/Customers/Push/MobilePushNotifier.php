<?php

namespace App\Domain\Customers\Push;

use App\Jobs\SendMobilePush;
use App\Models\MobileDevice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Notifications push de l'application mobile (clients et livreurs). Complément des emails et de l'actualisation
 * des écrans, jamais une condition du métier : sans configuration Firebase, rien n'est envoyé.
 * Contenu minimal : ni nom, ni téléphone, ni adresse dans la notification (docs mobile 10-PUSH).
 */
class MobilePushNotifier
{
    /** Textes affichés au client, par événement de commande. */
    private const ORDER_MESSAGES = [
        'order.pending_confirmation' => ['Commande reçue', 'Nous avons bien reçu votre commande %s.'],
        'order.accepted' => ['Commande confirmée', 'Votre commande %s est confirmée.'],
        'order.rejected' => ['Commande non retenue', 'Votre commande %s ne peut pas être honorée. Ouvrez pour en savoir plus.'],
        'order.ready' => ['Commande prête', 'Votre commande %s est prête.'],
        'order.picked_up' => ['Commande en route', 'Votre commande %s est en route.'],
        'order.delivered' => ['Commande livrée', 'Votre commande %s a été livrée. Bonne dégustation !'],
        'order.cancelled' => ['Commande annulée', 'Votre commande %s a été annulée.'],
        'order.delivery_failed' => ['Livraison non aboutie', 'La livraison de %s n\'a pas pu aboutir : nous vous recontactons.'],
        'payment.confirmed' => ['Paiement confirmé', 'Le paiement de votre commande %s est confirmé.'],
    ];

    public function __construct(private readonly MobilePushTransport $transport) {}

    public function enabled(): bool
    {
        return $this->transport->isConfigured();
    }

    public function orderEvent(Order $order, string $event): void
    {
        $userId = $order->customer?->user_id;
        [$title, $body] = self::ORDER_MESSAGES[$event] ?? ['Commande mise à jour', 'Votre commande %s a été mise à jour.'];
        if ($userId) {
            $this->toUser($userId, $title, sprintf($body, $order->reference), [
                'type' => 'order_update', 'event' => $event, 'reference' => $order->reference, 'route' => "/orders/{$order->reference}",
            ]);
        }
    }

    /** @param array<string, string> $data */
    public function toUser(string $userId, string $title, string $body, array $data): void
    {
        if (! $this->enabled() || ! MobileDevice::query()->where('user_id', $userId)->exists()) {
            return;
        }
        SendMobilePush::dispatch($userId, ['title' => $title, 'body' => $body, 'data' => $data])->afterCommit();
    }

    /** @param array{title:string, body:string, data:array<string,string>} $message */
    public function deliver(string $userId, array $message): int
    {
        $user = User::query()->find($userId);
        if (! $user || ! $user->is_active) {
            return 0;
        }
        $devices = $user->mobileDevices()->get();
        if ($devices->isEmpty()) {
            return 0;
        }
        $results = $this->transport->send($devices, $message);
        $sent = 0;
        foreach ($devices as $device) {
            $result = $results[$device->id] ?? 'failed';
            if ($result === 'sent') {
                $sent++;
                $device->forceFill(['last_success_at' => now()])->save();
            } elseif ($result === 'expired') {
                $device->delete();
            } else {
                Log::warning('Notification mobile non transmise', ['device' => $device->id]);
            }
        }

        return $sent;
    }
}
