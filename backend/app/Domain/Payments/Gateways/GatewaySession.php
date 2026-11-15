<?php

namespace App\Domain\Payments\Gateways;

use Carbon\CarbonInterface;

final class GatewaySession
{
    public function __construct(
        public readonly string $providerReference,
        public readonly ?string $checkoutUrl,
        public readonly ?CarbonInterface $expiresAt = null,
    ) {}
}
