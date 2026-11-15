<?php

namespace App\Domain\Payments\Gateways;

use RuntimeException;

class InvalidWebhookSignature extends RuntimeException {}
