<?php

namespace App\Domain\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\Request;

/**
 * Emplacement d'adaptation Orange Money (API Internet Orange Business Sénégal).
 *
 * Les spécifications techniques ne sont remises qu'après souscription marchande
 * (https://espaceclientv2.orange.sn/orange-money/api-internet). Conformément au TRD §6
 * (« ne pas prétendre connaître une API non contractée »), aucun appel n'est implémenté :
 * l'adaptateur est déclaré non configuré et ne peut pas être activé. Décision D-PAY-02.
 */
class OrangeMoneyGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'orange_money';
    }

    public function label(): string
    {
        return 'Orange Money';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function createSession(Payment $payment, Order $order): GatewaySession
    {
        throw new GatewayUnavailable('Orange Money : intégration en attente du contrat et des spécifications marchand.');
    }

    public function fetchStatus(Payment $payment): GatewayStatus
    {
        throw new GatewayUnavailable('Orange Money : intégration en attente du contrat et des spécifications marchand.');
    }

    public function refund(Refund $refund, Payment $payment): ?string
    {
        return null;
    }

    public function supportsPartialRefund(): bool
    {
        return false;
    }

    public function validateWebhook(Request $request): WebhookEvent
    {
        throw new InvalidWebhookSignature('Orange Money non configuré.');
    }
}
