<?php

namespace App\Domain\Deliveries;

enum AssignmentStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public static function active(): array
    {
        return [self::Proposed->value, self::Accepted->value, self::PickedUp->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposée',
            self::Accepted => 'Acceptée',
            self::Refused => 'Refusée',
            self::PickedUp => 'Enlevée',
            self::Delivered => 'Livrée',
            self::Failed => 'Échec',
            self::Cancelled => 'Annulée',
        };
    }
}
