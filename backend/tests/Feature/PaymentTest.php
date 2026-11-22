<?php

namespace Tests\Feature;

use App\Domain\Payments\Gateways\SimulatorGateway;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\RefundService;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/** PAY-005 — Scénarios B et H ; T-005 à T-010 */
class PaymentTest extends TestCase
{
    private function initiate(string $reference, string $token, string $key = 'pay-key-000001')
    {
        return $this->postJson("/api/v1/orders/{$reference}/payments", ['method' => 'simulator'], ['X-Tracking-Token' => $token, 'Idempotency-Key' => $key]);
    }

    private function signedWebhook(array $event, ?string $signature = null)
    {
        $body = json_encode($event);

        return $this->call('POST', '/api/v1/webhooks/payments/simulator', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIMULATOR_SIGNATURE' => $signature ?? app(SimulatorGateway::class)->sign($body),
        ], $body);
    }

    public function test_scenario_b_valid_webhook_marks_order_paid_once(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated()->assertJsonPath('data.is_simulated', true);
        $this->assertSame('pending', $order->fresh()->payment_status->value);

        $payment = Payment::query()->firstOrFail();
        $event = app(SimulatorGateway::class)->completeSession($payment->provider_reference, 'success');
        $this->signedWebhook($event)->assertOk()->assertJsonPath('status', 'accepted');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame($order->total_xof, $order->paid_xof);
        $this->assertSame('pending_confirmation', $order->status->value, 'Le paiement ne change pas l\'état commercial');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
        $this->assertDatabaseHas('payment_events', ['provider_event_id' => $event['id'], 'processing_status' => 'processed']);

        // Scénario H / T-008 : même événement rejoué 3 fois -> une seule écriture financière.
        $this->signedWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->signedWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertSame($order->total_xof, $order->fresh()->paid_xof);

        // Nouvel identifiant d'événement mais même paiement : toujours aucun double encaissement.
        $event['id'] = 'SIMEVT-OTHER-'.Str::random(6);
        $this->signedWebhook($event)->assertOk();
        $this->assertSame($order->total_xof, $order->fresh()->paid_xof);
    }

    public function test_forged_webhook_signature_is_rejected_without_change(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $payment = Payment::query()->firstOrFail();
        $event = ['id' => 'SIMEVT-FORGED', 'type' => 'checkout.session.completed', 'data' => ['session_id' => $payment->provider_reference, 'amount' => $payment->amount_xof, 'currency' => 'XOF']];

        $this->signedWebhook($event, 'deadbeef')->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame('pending', $order->fresh()->payment_status->value);
    }

    public function test_signed_webhook_is_not_trusted_without_provider_confirmation(): void
    {
        // Webhook correctement signé, mais l'état côté prestataire n'est pas « réussi » : la commande n'est pas payée.
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $payment = Payment::query()->firstOrFail();
        $this->signedWebhook(['id' => 'SIMEVT-LIE', 'type' => 'checkout.session.completed', 'data' => ['session_id' => $payment->provider_reference]])->assertOk();

        $this->assertSame('pending', $order->fresh()->payment_status->value);
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_browser_return_without_webhook_stays_pending(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();

        $this->postJson("/api/v1/orders/{$order->reference}/payments/refresh", [], ['X-Tracking-Token' => $token])
            ->assertOk()->assertJsonPath('data.payment_status', 'pending');
    }

    /** Audit B2 : une session encore « en attente » chez le prestataire n'est jamais close localement. */
    public function test_reconciliation_never_expires_a_payment_still_pending_at_provider(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $payment = Payment::query()->firstOrFail();
        $payment->update(['expires_at' => now()->subHour(), 'created_at' => now()->subHours(2)]);

        $this->artisan('mk:reconcile-payments')->expectsOutputToContain('1 paiement(s) vérifié(s)')->assertSuccessful();
        $this->assertSame('pending', $payment->fresh()->status->value);

        // Le client valide finalement : le paiement est bien enregistré.
        $this->signedWebhook(app(SimulatorGateway::class)->completeSession($payment->provider_reference, 'success'))->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    /** Audit B2 : un succès confirmé par le prestataire après une clôture locale n'est jamais perdu. */
    public function test_late_success_on_closed_payment_is_recorded_and_flagged(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $payment = Payment::query()->firstOrFail();
        $payment->update(['status' => 'expired']); // clôturé de notre côté (ancien comportement, ou échec annoncé puis rectifié)

        $this->signedWebhook(app(SimulatorGateway::class)->completeSession($payment->provider_reference, 'success'))->assertOk();

        $order->refresh();
        $this->assertSame('succeeded', $payment->fresh()->status->value);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame($order->total_xof, $order->paid_xof);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'payment']);
        $this->assertTrue($order->events()->get()->contains(fn ($e) => ($e->metadata['event'] ?? null) === 'payment.late_success'));

        // Rejouer la confirmation ne compte pas deux fois l'encaissement.
        app(PaymentService::class)->reconcile($payment->fresh());
        $this->assertSame($order->total_xof, $order->fresh()->paid_xof);
    }

    /** Audit E2 : un double encaissement est détecté et le trop-perçu remboursé, sans avoir. */
    public function test_overpayment_opens_overpayment_refund_without_credit_note(): void
    {
        app(Settings::class)->set('invoicing.issue_on', 'paid');
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $online = Payment::query()->firstOrFail();
        $online->update(['status' => 'expired']); // session crue terminée…

        // … le client paie en espèces, enregistrées par le commercial…
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->actingAs($commercial)->postJson("/api/v1/admin/orders/{$order->id}/payments/manual", ['amount_xof' => $order->total_xof, 'method' => 'cash', 'reason' => 'Espèces reçues au magasin'])->assertCreated();
        // … puis le paiement en ligne aboutit malgré tout chez le prestataire.
        $this->signedWebhook(app(SimulatorGateway::class)->completeSession($online->provider_reference, 'success'))->assertOk();

        $order->refresh();
        $this->assertSame(2 * $order->total_xof, $order->paid_xof);
        $refund = Refund::query()->where('order_id', $order->id)->sole();
        $this->assertSame('overpayment', $refund->kind);
        $this->assertSame($order->total_xof, $refund->amount_xof);
        $this->assertSame('requested', $refund->status, 'Le trop-perçu reste soumis au double contrôle.');

        $this->actingAs($this->staff(User::ROLE_ADMIN))->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk();
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count(), 'Une facture, aucun avoir pour un trop-perçu.');

        // Audit du 22/11/2026 (A-02) : une fois le trop-perçu restitué, la vente reste intégralement payée
        // et la commande peut toujours être acceptée.
        if ($refund->fresh()->status !== 'succeeded') {
            $this->actingAs($this->staff(User::ROLE_ADMIN))->postJson("/api/v1/admin/refunds/{$refund->id}/complete", ['provider_ref' => 'VIR-TEST-1'])->assertOk();
        }
        $order->refresh();
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame('paid', $order->payment_status->value);
        $this->transition($commercial, $order, 'accept')->assertOk();
    }

    /** Audit E2 / M9 : pas d'encaissement manuel si une session en ligne est ouverte, ni sur une commande annulée. */
    public function test_manual_payment_guards(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->actingAs($commercial)->postJson("/api/v1/admin/orders/{$order->id}/payments/manual", ['amount_xof' => $order->total_xof, 'method' => 'cash', 'reason' => 'Espèces reçues'])
            ->assertStatus(409)->assertJsonPath('error.code', 'online_payment_in_progress');

        [$cancelled] = $this->createOrder();
        $this->transition($commercial, $cancelled, 'cancel', ['reason' => 'Client injoignable'])->assertOk();
        $this->actingAs($commercial)->postJson("/api/v1/admin/orders/{$cancelled->id}/payments/manual", ['amount_xof' => $cancelled->total_xof, 'method' => 'cash', 'reason' => 'Espèces reçues'])
            ->assertStatus(409)->assertJsonPath('error.code', 'order_not_payable');
    }

    /** Audit M3 : les frais ne changent pas pendant qu'un paiement en ligne est en cours. */
    public function test_delivery_fee_cannot_change_during_online_payment(): void
    {
        [$order, $token] = $this->createOrder(null, $this->zone(['delivery_fee_xof' => 1500]));
        $this->initiate($order->reference, $token)->assertCreated();
        $other = $this->zone(['delivery_fee_xof' => 3000]);
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->patchJson("/api/v1/admin/orders/{$order->id}/details", ['delivery' => ['zone_id' => $other->id, 'district' => 'Ailleurs'], 'reason' => 'Changement d\'adresse'])
            ->assertStatus(409)->assertJsonPath('error.code', 'online_payment_in_progress');
        $this->assertSame(1500, $order->fresh()->delivery_fee_xof);
    }

    /** Audit M5 : un montant reçu différent du montant attendu clôt la tentative (à examiner), sans boucle. */
    public function test_amount_mismatch_closes_payment_for_review_once(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $payment = Payment::query()->firstOrFail();
        $event = app(SimulatorGateway::class)->completeSession($payment->provider_reference, 'success');
        $state = Cache::get("payment-simulator:{$payment->provider_reference}");
        Cache::put("payment-simulator:{$payment->provider_reference}", ['amount' => 100] + $state, now()->addDay());

        $this->signedWebhook($event)->assertOk();
        $payment->refresh();
        $this->assertSame('failed', $payment->status->value);
        $this->assertSame('amount_mismatch', $payment->failure_reason);
        $this->assertSame(0, $order->fresh()->paid_xof, 'Un montant incohérent n\'est jamais compté comme encaissé.');

        // La tâche planifiée ne reprend plus ce paiement : une seule trace au journal.
        $payment->update(['created_at' => now()->subHour()]);
        $this->artisan('mk:reconcile-payments')->expectsOutputToContain('0 paiement(s)')->assertSuccessful();
        $this->assertSame(1, $order->events()->get()->filter(fn ($e) => ($e->metadata['event'] ?? null) === 'payment.amount_mismatch')->count());
    }

    public function test_double_payment_click_reuses_open_session(): void
    {
        [$order, $token] = $this->createOrder();
        $a = $this->initiate($order->reference, $token, 'pay-click-000001')->assertCreated()->json('data.payment_id');
        $b = $this->initiate($order->reference, $token, 'pay-click-000002')->assertCreated()->json('data.payment_id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_failed_payment_sets_failed_status_and_allows_retry(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $payment = Payment::query()->firstOrFail();
        $this->postJson("/api/v1/dev/payment-simulator/{$payment->provider_reference}/complete", ['outcome' => 'failure'])->assertOk();

        $this->assertSame('failed', $order->fresh()->payment_status->value);
        $this->initiate($order->reference, $token, 'pay-retry-000001')->assertCreated();
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(1, $order->fresh()->items()->count(), 'Aucune duplication de commande');
    }

    public function test_online_order_cannot_be_accepted_before_payment_confirmation(): void
    {
        [$order] = $this->createOrder();
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept')->assertStatus(409)->assertJsonPath('error.code', 'payment_required');
    }

    public function test_rejecting_paid_order_opens_refund_with_double_control(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $commercial = $this->staff(User::ROLE_COMMERCIAL);

        $this->transition($commercial, $order, 'reject', ['reason' => 'Rupture de mascarpone'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $refund = Refund::query()->firstOrFail();
        $this->assertSame('requested', $refund->status);
        $this->assertSame($order->total_xof, $refund->amount_xof);

        // Le commercial ne peut pas approuver ; un admin différent du demandeur le peut.
        $this->actingAs($commercial)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertForbidden();
        $admin = $this->staff(User::ROLE_ADMIN);
        $this->actingAs($admin)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('refunded', $order->fresh()->payment_status->value);
        $this->assertSame($order->total_xof, $order->fresh()->refunded_xof);
    }

    /** Audit E3 : un rejet décidé sur une donnée périmée ne doit jamais écraser un remboursement déjà exécuté. */
    public function test_stale_reject_cannot_overwrite_executed_refund(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'reject', ['reason' => 'Rupture de stock'])->assertOk();
        $stale = Refund::query()->firstOrFail(); // chargé « requested » par l'approbateur B

        $this->actingAs($this->staff(User::ROLE_ADMIN))->postJson("/api/v1/admin/refunds/{$stale->id}/approve")->assertOk()->assertJsonPath('data.status', 'succeeded');

        try {
            app(RefundService::class)->reject($stale, $this->staff(User::ROLE_ADMIN), 'Doublon');
            $this->fail('Le rejet aurait dû être refusé.');
        } catch (DomainException $e) {
            $this->assertSame('refund_not_pending', $e->errorCode);
        }
        $this->assertSame('succeeded', $stale->fresh()->status);
    }

    public function test_requester_cannot_approve_own_refund_and_refund_is_capped(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $admin = $this->staff(User::ROLE_ADMIN);
        $this->actingAs($admin)->postJson("/api/v1/admin/orders/{$order->id}/refunds", ['amount_xof' => $order->total_xof + 1, 'reason' => 'Geste commercial'])
            ->assertStatus(422)->assertJsonPath('error.code', 'refund_exceeds_captured');
        $refundId = $this->postJson("/api/v1/admin/orders/{$order->id}/refunds", ['amount_xof' => 1000, 'reason' => 'Geste commercial'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/refunds/{$refundId}/approve")->assertForbidden();
    }

    public function test_late_payment_after_cancellation_opens_refund(): void
    {
        [$order, $token] = $this->createOrder();
        $this->initiate($order->reference, $token)->assertCreated();
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'cancel', ['reason' => 'Client injoignable'])->assertOk();

        $payment = Payment::query()->firstOrFail();
        $this->postJson("/api/v1/dev/payment-simulator/{$payment->provider_reference}/complete", ['outcome' => 'success'])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertDatabaseHas('refunds', ['order_id' => $order->id, 'status' => 'requested', 'amount_xof' => $order->total_xof]);
    }

    public function test_manual_payment_requires_permission_and_reason(): void
    {
        [$order] = $this->createOrder();
        $this->actingAs($this->staff(User::ROLE_PREPARATEUR))->postJson("/api/v1/admin/orders/{$order->id}/payments/manual", ['amount_xof' => 100, 'method' => 'cash', 'reason' => 'Espèces'])->assertForbidden();
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->postJson("/api/v1/admin/orders/{$order->id}/payments/manual", ['amount_xof' => $order->total_xof, 'method' => 'cash', 'reason' => 'Espèces reçues en boutique'])
            ->assertCreated();
        $this->assertSame('paid', $order->fresh()->payment_status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.manual_recorded']);
    }
}
