<?php

namespace App\Domain\Orders;

enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Non payée',
            self::Pending => 'Paiement en vérification',
            self::Paid => 'Payée',
            self::Failed => 'Paiement échoué',
            self::PartiallyRefunded => 'Partiellement remboursée',
            self::Refunded => 'Remboursée',
        };
    }
}
