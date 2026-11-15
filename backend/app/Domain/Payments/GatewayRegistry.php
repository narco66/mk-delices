<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Gateways\OrangeMoneyGateway;
use App\Domain\Payments\Gateways\PaymentGateway;
use App\Domain\Payments\Gateways\SimulatorGateway;
use App\Domain\Payments\Gateways\WaveGateway;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;

class GatewayRegistry
{
    public const CASH_ON_DELIVERY = 'cash_on_delivery';

    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(private readonly Settings $settings, WaveGateway $wave, OrangeMoneyGateway $orange, SimulatorGateway $simulator)
    {
        $this->gateways = [$wave->code() => $wave, $orange->code() => $orange, $simulator->code() => $simulator];
    }

    public function get(string $code): PaymentGateway
    {
        return $this->gateways[$code] ?? throw new DomainException('unknown_provider', 'Prestataire de paiement inconnu.', 404);
    }

    /**
     * Moyens de paiement réellement proposables : activés en paramètre ET configurés techniquement.
     *
     * @return list<array{code:string, label:string, online:bool, test_only:bool}>
     */
    public function availableMethods(): array
    {
        $enabled = (array) $this->settings->get('payments.methods', []);
        $methods = [];
        foreach ($this->gateways as $code => $gateway) {
            if ($code === 'simulator') {
                continue;
            }
            if (($enabled[$code] ?? false) && $gateway->isConfigured()) {
                $methods[] = ['code' => $code, 'label' => $gateway->label(), 'online' => true, 'test_only' => false];
            }
        }
        if ($enabled[self::CASH_ON_DELIVERY] ?? false) {
            $methods[] = ['code' => self::CASH_ON_DELIVERY, 'label' => 'Paiement à la livraison', 'online' => false, 'test_only' => false];
        }
        if ($this->gateways['simulator']->isConfigured()) {
            $methods[] = ['code' => 'simulator', 'label' => 'Paiement simulé (environnement de test)', 'online' => true, 'test_only' => true];
        }

        return $methods;
    }

    public function isAvailable(string $code): bool
    {
        return in_array($code, array_column($this->availableMethods(), 'code'), true);
    }

    public function isOnline(string $code): bool
    {
        return isset($this->gateways[$code]);
    }
}
