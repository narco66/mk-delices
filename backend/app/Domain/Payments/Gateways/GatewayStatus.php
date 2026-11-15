<?php

namespace App\Domain\Payments\Gateways;

final class GatewayStatus
{
    /** @param string $status succeeded|pending|failed|cancelled|expired */
    public function __construct(
        public readonly string $status,
        public readonly ?int $amount,
        public readonly ?string $currency,
        public readonly ?string $rawStatus = null,
    ) {}
}
