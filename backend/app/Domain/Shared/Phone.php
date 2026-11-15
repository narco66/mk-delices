<?php

namespace App\Domain\Shared;

final class Phone
{
    /** Normalise un numéro sénégalais au format E.164 (+221XXXXXXXXX) ou renvoie null. */
    public static function normalizeSenegal(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '00221')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '221') && strlen($digits) === 12) {
            $digits = substr($digits, 3);
        }
        if (! preg_match('/^(7[05678]|3[03])\d{7}$/', $digits)) {
            return null;
        }

        return '+221'.$digits;
    }

    /** Masque un numéro pour les journaux et les rôles n'en ayant pas besoin : +221 77 *** ** 12. */
    public static function mask(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 6) {
            return $phone === null ? null : '***';
        }

        return substr($phone, 0, 6).' *** ** '.substr($phone, -2);
    }
}
