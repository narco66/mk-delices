<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Notification;

/** Notification interne du back-office (canal base de données), sans donnée personnelle. */
class StaffOrderActivity extends Notification
{
    private const MESSAGES = [
        'order.pending_confirmation' => 'Nouvelle commande à confirmer',
        'order.accepted' => 'Commande acceptée : à préparer',
        'order.ready' => 'Commande prête : à affecter à un livreur',
        'order.delivery_failed' => 'Échec de livraison à traiter',
        'delivery.without_code' => 'Livraison confirmée sans code de remise : à vérifier avec le client',
        'delivery.code_locked' => 'Trop de codes de remise erronés : appeler le livreur',
        'delivery.not_accepted' => 'Mission non acceptée par le livreur : relancer ou réaffecter',
        'order.cancelled' => 'Commande annulée',
        'payment.confirmed' => 'Paiement confirmé par le prestataire',
    ];

    public function __construct(private readonly Order $order, private readonly string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'message' => self::MESSAGES[$this->event] ?? $this->event,
            'order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'total_xof' => $this->order->total_xof,
        ];
    }
}
