<?php

namespace App\Domain\Shared;

use RuntimeException;

/**
 * Erreur métier contrôlée, convertie en réponse API normalisée
 * { error: { code, message, details }, request_id }.
 */
class DomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }

    public static function unprocessable(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }

    public static function forbidden(string $message = 'Action non autorisée.'): self
    {
        return new self('forbidden', $message, 403);
    }
}
