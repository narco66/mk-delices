<?php

namespace App\Domain\Orders;

use App\Models\Order;

/** Référence commande non prédictible : MKD-XXXX-XXXX (alphabet sans caractères ambigus, ~40 bits). */
final class OrderReference
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function generate(): string
    {
        do {
            $chars = '';
            for ($i = 0; $i < 8; $i++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $reference = 'MKD-'.substr($chars, 0, 4).'-'.substr($chars, 4);
        } while (Order::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
