<?php

namespace App\Domain\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Prestataire SIMULÉ — strictement réservé aux environnements local/testing/staging (jamais en production).
 * Il reproduit le comportement d'un PSP : session, état côté prestataire, webhook signé HMAC-SHA256.
 * Aucun argent réel n'est concerné.
 */
class SimulatorGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Simulator-Signature';

    public function code(): string
    {
        return 'simulator';
    }

    public function label(): string
    {
        return 'Paiement simulé (test)';
    }

    public function isConfigured(): bool
    {
        return (bool) config('mkdelices.payments.simulator_enabled') && (string) config('mkdelices.payments.simulator_secret') !== '';
    }

    public function createSession(Payment $payment, Order $order): GatewaySession
    {
        $reference = 'SIM-'.Str::upper(Str::random(16));
        $this->storeProviderState($reference, ['status' => 'pending', 'amount' => $payment->amount_xof, 'currency' => 'XOF']);
        $url = rtrim(config('mkdelices.frontend_url'), '/')."/paiement/simulateur/{$reference}?ref={$order->reference}";

        return new GatewaySession($reference, $url, now()->addMinutes((int) config('mkdelices.payments.session_ttl_minutes')));
    }

    public function fetchStatus(Payment $payment): GatewayStatus
    {
        $state = $this->providerState((string) $payment->provider_reference);
        if ($state === null) {
            return new GatewayStatus('failed', null, null, 'unknown_session');
        }

        return new GatewayStatus($state['status'], $state['amount'], $state['currency'], $state['status']);
    }

    public function refund(Refund $refund, Payment $payment): ?string
    {
        return 'SIM-RF-'.Str::upper(Str::random(12));
    }

    public function validateWebhook(Request $request): WebhookEvent
    {
        $body = $request->getContent();
        $signature = (string) $request->header(self::SIGNATURE_HEADER);
        if (! $this->isConfigured() || $signature === '' || ! hash_equals($this->sign($body), $signature)) {
            throw new InvalidWebhookSignature('Signature simulateur invalide.');
        }
        $data = json_decode($body, true);
        if (! is_array($data) || empty($data['id']) || empty($data['type'])) {
            throw new InvalidWebhookSignature('Charge utile simulateur invalide.');
        }

        return new WebhookEvent($data['id'], $data['type'], $data['data']['session_id'] ?? null, $data);
    }

    /** Côté « prestataire » : le payeur valide ou refuse dans l'écran simulé. */
    public function completeSession(string $reference, string $outcome): array
    {
        $state = $this->providerState($reference);
        if ($state === null) {
            throw new GatewayUnavailable('Session simulée inconnue.');
        }
        $state['status'] = $outcome === 'success' ? 'succeeded' : 'failed';
        $this->storeProviderState($reference, $state);

        return [
            'id' => 'SIMEVT-'.Str::upper(Str::random(16)),
            'type' => $outcome === 'success' ? 'checkout.session.completed' : 'checkout.session.payment_failed',
            'data' => ['session_id' => $reference, 'amount' => $state['amount'], 'currency' => $state['currency'], 'payment_status' => $state['status']],
        ];
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('mkdelices.payments.simulator_secret'));
    }

    private function storeProviderState(string $reference, array $state): void
    {
        Cache::put("payment-simulator:{$reference}", $state, now()->addDays(7));
    }

    private function providerState(string $reference): ?array
    {
        return Cache::get("payment-simulator:{$reference}");
    }
}
