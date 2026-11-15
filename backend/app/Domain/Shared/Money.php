<?php

namespace App\Domain\Shared;

final class Money
{
    /** Format XOF sans décimales : 12 500 FCFA. */
    public static function format(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{202F}").' FCFA';
    }
}
