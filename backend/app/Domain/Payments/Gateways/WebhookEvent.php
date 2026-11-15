<?php

namespace App\Domain\Payments\Gateways;

final class WebhookEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?string $providerReference,
        public readonly array $payloadRedacted,
    ) {}
}
