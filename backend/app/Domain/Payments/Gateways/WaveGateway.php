<?php

namespace App\Domain\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Adaptateur Wave Checkout API (https://docs.wave.com/checkout).
 *
 * ATTENTION : implémentation non encore exécutée contre un compte marchand.
 * Les noms de champs suivent la documentation publique consultée ; ils DOIVENT être revalidés
 * en sandbox/marchand avant activation (voir docs/DEV-DECISIONS-A-VALIDER.md, D-PAY-01).
 * L'adaptateur reste inactif tant que WAVE_API_KEY et WAVE_WEBHOOK_SECRET ne sont pas fournis.
 */
class WaveGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'wave';
    }

    public function label(): string
    {
        return 'Wave';
    }

    public function isConfigured(): bool
    {
        return filled(config('mkdelices.payments.wave.api_key')) && filled(config('mkdelices.payments.wave.webhook_secret'));
    }

    public function createSession(Payment $payment, Order $order): GatewaySession
    {
        $front = rtrim(config('mkdelices.frontend_url'), '/');
        $response = $this->http()->post('/v1/checkout/sessions', [
            'amount' => (string) $payment->amount_xof,
            'currency' => 'XOF',
            'client_reference' => $payment->id,
            'success_url' => "{$front}/paiement/{$order->reference}/retour",
            'error_url' => "{$front}/paiement/{$order->reference}/retour",
        ]);
        if (! $response->successful()) {
            throw new GatewayUnavailable('Wave indisponible ('.$response->status().').');
        }
        $data = $response->json();

        return new GatewaySession(
            (string) $data['id'],
            $data['wave_launch_url'] ?? null,
            isset($data['when_expires']) ? CarbonImmutable::parse($data['when_expires']) : null,
        );
    }

    public function fetchStatus(Payment $payment): GatewayStatus
    {
        $response = $this->http()->get('/v1/checkout/sessions/'.rawurlencode((string) $payment->provider_reference));
        if (! $response->successful()) {
            throw new GatewayUnavailable('Consultation Wave impossible ('.$response->status().').');
        }
        $data = $response->json();
        $status = match (true) {
            ($data['payment_status'] ?? null) === 'succeeded' => 'succeeded',
            ($data['checkout_status'] ?? null) === 'expired' => 'expired',
            ($data['payment_status'] ?? null) === 'cancelled' => 'cancelled',
            default => 'pending',
        };

        return new GatewayStatus($status, isset($data['amount']) ? (int) $data['amount'] : null, $data['currency'] ?? null, ($data['checkout_status'] ?? '').'/'.($data['payment_status'] ?? ''));
    }

    public function refund(Refund $refund, Payment $payment): ?string
    {
        $response = $this->http()->post('/v1/checkout/sessions/'.rawurlencode((string) $payment->provider_reference).'/refund');
        if (! $response->successful()) {
            throw new GatewayUnavailable('Remboursement Wave refusé ('.$response->status().').');
        }

        return 'wave-refund-'.$payment->provider_reference;
    }

    public function validateWebhook(Request $request): WebhookEvent
    {
        // En-tête « Wave-Signature: t=<timestamp>,v1=<hmac> » ; HMAC-SHA256(secret, timestamp + corps brut).
        $header = (string) $request->header('Wave-Signature');
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k !== null && $v !== null) {
                $parts[$k][] = $v;
            }
        }
        $timestamp = $parts['t'][0] ?? null;
        $body = $request->getContent();
        if (! $this->isConfigured() || $timestamp === null || abs(time() - (int) $timestamp) > 300) {
            throw new InvalidWebhookSignature('Signature Wave absente ou expirée.');
        }
        $expected = hash_hmac('sha256', $timestamp.$body, (string) config('mkdelices.payments.wave.webhook_secret'));
        $valid = false;
        foreach ($parts['v1'] ?? [] as $candidate) {
            $valid = $valid || hash_equals($expected, $candidate);
        }
        if (! $valid) {
            throw new InvalidWebhookSignature('Signature Wave invalide.');
        }
        $data = json_decode($body, true) ?? [];

        return new WebhookEvent((string) ($data['id'] ?? ''), (string) ($data['type'] ?? 'unknown'), $data['data']['id'] ?? null, [
            'id' => $data['id'] ?? null,
            'type' => $data['type'] ?? null,
            'data' => array_intersect_key($data['data'] ?? [], array_flip(['id', 'amount', 'currency', 'checkout_status', 'payment_status', 'client_reference', 'when_completed'])),
        ]);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('mkdelices.payments.wave.api_base'))
            ->withToken((string) config('mkdelices.payments.wave.api_key'))
            ->acceptJson()->timeout(15)->retry(2, 500, throw: false);
    }
}
