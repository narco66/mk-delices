<?php

namespace App\Domain\Orders;

enum OrderStatus: string
{
    case Draft = 'draft';
    case PendingConfirmation = 'pending_confirmation';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::PendingConfirmation => 'En attente de confirmation',
            self::Accepted => 'Acceptée',
            self::Preparing => 'En préparation',
            self::Ready => 'Prête',
            self::Assigned => 'Confiée au livreur',
            self::PickedUp => 'En cours de livraison',
            self::Delivered => 'Livrée',
            self::DeliveryFailed => 'Échec de livraison',
            self::Cancelled => 'Annulée',
            self::Rejected => 'Refusée',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Rejected], true);
    }

    /** Statuts comptant dans la capacité d'un créneau. */
    public static function occupyingSlot(): array
    {
        return array_map(fn (self $s) => $s->value, [
            self::PendingConfirmation, self::Accepted, self::Preparing, self::Ready,
            self::Assigned, self::PickedUp, self::DeliveryFailed,
        ]);
    }
}
