<?php

namespace App\Domain\Shared;

use Illuminate\Support\Str;

/** Identifiant de corrélation de la requête courante (en-tête X-Request-Id). */
final class RequestContext
{
    private static ?string $requestId = null;

    public static function id(): string
    {
        return self::$requestId ??= (string) Str::uuid();
    }

    public static function set(?string $id): void
    {
        self::$requestId = $id && preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $id) ? $id : (string) Str::uuid();
    }

    public static function reset(): void
    {
        self::$requestId = null;
    }
}
