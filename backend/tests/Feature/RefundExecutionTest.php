<?php

namespace Tests\Feature;

use App\Domain\Payments\RefundService;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Audit B1 / F3 : exécution des remboursements chez le prestataire (Wave simulé par Http::fake).
 * Aucun remboursement ne doit pouvoir être payé deux fois ni dépasser ce qui a été encaissé sur le paiement.
 */
class RefundExecutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mkdelices.payments.wave.api_key' => 'test-key',
            'mkdelices.payments.wave.webhook_secret' => 'test-secret',
            'mkdelices.payments.wave.api_base' => 'https://wave.test',
        ]);
    }

    /** Commande payée, présentée comme réglée par Wave. */
    private function wavePaidOrder(): array
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $payment->update(['provider' => 'wave', 'provider_reference' => 'cos-TEST123']);

        return [$order->fresh(), $payment->fresh()];
    }

    private function requestRefund(Order $order, int $amount): string
    {
        return $this->actingAs($this->staff(User::ROLE_COMMERCIAL))
            ->postJson("/api/v1/admin/orders/{$order->id}/refunds", ['amount_xof' => $amount, 'reason' => 'Remboursement de test'])->assertCreated()->json('data.id');
    }

    private function approve(string $refundId)
    {
        return $this->actingAs($this->staff(User::ROLE_ADMIN))->postJson("/api/v1/admin/refunds/{$refundId}/approve");
    }

    public function test_partial_wave_refund_is_never_sent_automatically(): void
    {
        Http::fake();
        [$order] = $this->wavePaidOrder();
        $id = $this->requestRefund($order, 2000);

        $this->approve($id)->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.execution_note', fn ($n) => str_contains($n, 'partiel'));
        Http::assertNothingSent();
        $this->assertSame(0, $order->fresh()->refunded_xof);

        // Exécution manuelle depuis l'espace marchand, puis confirmation avec la référence.
        $this->postJson("/api/v1/admin/refunds/{$id}/complete", ['provider_ref' => 'WAVE-MANUEL-1'])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(2000, $order->fresh()->refunded_xof);
    }

    public function test_full_wave_refund_is_sent_once_with_idempotency_key(): void
    {
        Http::fake(['wave.test/*' => Http::response(['id' => 'rf-1'], 200)]);
        [$order, $payment] = $this->wavePaidOrder();
        $id = $this->requestRefund($order, $payment->amount_xof);

        $this->approve($id)->assertOk()->assertJsonPath('data.status', 'succeeded');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/checkout/sessions/cos-TEST123/refund') && $r->header('Idempotency-Key')[0] === "mkd-refund-{$id}");
        $this->assertSame($payment->amount_xof, $order->fresh()->refunded_xof);
    }

    public function test_provider_timeout_leaves_refund_for_manual_check_without_retry(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Délai dépassé');
        });
        [$order, $payment] = $this->wavePaidOrder();
        $id = $this->requestRefund($order, $payment->amount_xof);

        $this->approve($id)->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.execution_note', fn ($n) => str_contains($n, 'non confirmée'));
        $refund = Refund::query()->findOrFail($id);
        $this->assertNotNull($refund->auto_execution_started_at);
        $this->assertSame(1, $calls);

        // Aucun moyen de redéclencher l'appel automatique : un second « approuver » est refusé.
        $this->approve($id)->assertStatus(409);
        $this->assertSame(1, $calls, 'Jamais de second appel au prestataire.');
        // Le solde de ce paiement est réservé : une nouvelle demande intégrale ne peut pas partir automatiquement.
        $this->assertSame(0, app(RefundService::class)->remainingOnPayment($payment->fresh()));
    }

    public function test_offline_payment_refund_requires_manual_execution(): void
    {
        Http::fake();
        [$order, $payment] = $this->wavePaidOrder();
        $payment->update(['is_manual' => true, 'provider' => 'manual:cash']);
        $id = $this->requestRefund($order, 1000);

        $this->approve($id)->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.execution_note', fn ($n) => str_contains($n, 'hors ligne'));
        Http::assertNothingSent();
    }
}
