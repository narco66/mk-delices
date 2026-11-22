<?php

namespace App\Domain\Payments;

use App\Domain\Orders\OrderJournal;
use App\Domain\Orders\OrderNotifier;
use App\Domain\Orders\OrderPaymentStatus;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\Gateways\GatewayStatus;
use App\Domain\Payments\Gateways\GatewayUnavailable;
use App\Domain\Payments\Gateways\InvalidWebhookSignature;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Jobs\IssueInvoice;
use App\Jobs\ProcessPaymentEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Encaissement : une commande n'est « paid » qu'après confirmation serveur vérifiée du prestataire
 * (webhook authentifié + consultation de l'état), jamais sur retour navigateur (BR-005, T-009).
 */
class PaymentService
{
    public function __construct(
        private readonly OrderJournal $journal,
        private readonly GatewayRegistry $registry,
        private readonly RefundService $refunds,
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    public function initiate(Order $order, string $method, string $idempotencyKey): Payment
    {
        if (! $this->registry->isAvailable($method) || ! $this->registry->isOnline($method)) {
            throw DomainException::unprocessable('payment_method_unavailable', 'Ce moyen de paiement en ligne n\'est pas disponible.');
        }
        $gateway = $this->registry->get($method);

        // 1) Sous verrou : contrôles et réservation de la tentative (statut « initiated », sans session).
        $prepared = DB::transaction(function () use ($order, $method, $idempotencyKey) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertPayable($locked);
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->order_id !== $locked->id) {
                    throw DomainException::conflict('idempotency_key_reused', 'Clé d\'idempotence déjà utilisée.');
                }

                return $this->settleUnstartedAttempt($existing) ?? $existing->fresh();
            }
            // Une seule session ouverte par commande et prestataire : on la réutilise (T-005).
            $open = Payment::query()->where('order_id', $locked->id)->where('provider', $method)
                ->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()->first();
            if ($open = $open ? $this->settleUnstartedAttempt($open) : null) {
                return $open;
            }

            return Payment::query()->create([
                'order_id' => $locked->id,
                'provider' => $method,
                'status' => PaymentStatus::Initiated,
                'amount_xof' => $locked->total_xof - $locked->paid_xof,
                'currency' => 'XOF',
                'idempotency_key' => $idempotencyKey,
                'is_simulated' => $method === 'simulator',
            ]);
        });
        if ($prepared->status !== PaymentStatus::Initiated || $prepared->checkout_url !== null) {
            return $prepared;
        }

        // 2) Hors verrou (audit A-06) : l'appel au prestataire (jusqu'à 15 s) ne bloque ni les webhooks ni
        // le back-office. Tant que la tentative est « initiated », le montant de la commande ne peut pas
        // changer (hasOpenOnlineSession) : la session est créée pour le bon montant.
        try {
            $session = $gateway->createSession($prepared, $order->fresh());
        } catch (GatewayUnavailable) {
            // Aucune session n'existe chez le prestataire : la tentative est retirée, un nouvel essai repart de zéro.
            $prepared->delete();

            throw new DomainException('psp_unavailable', 'Le service de paiement est momentanément indisponible. Votre commande est conservée.', 503);
        }

        // 3) Sous verrou : la commande a pu être annulée ou payée pendant l'appel.
        $result = DB::transaction(function () use ($prepared, $session, $method) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($prepared->order_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($prepared->id);
            $payment->update([
                'provider_reference' => $session->providerReference,
                'checkout_url' => $session->checkoutUrl,
                'expires_at' => $session->expiresAt,
            ]);
            // Refus enregistré (session close de notre côté, référence conservée pour le rapprochement),
            // puis signalé après validation de la transaction.
            if ($refusal = $this->payabilityError($locked)) {
                $payment->update(['status' => PaymentStatus::Cancelled, 'failure_reason' => 'order_not_payable']);

                return $refusal;
            }
            $payment->update(['status' => PaymentStatus::Pending]);
            // Audit E4 : une tentative en ligne (peut-être abandonnée) ne remplace jamais « paiement à la livraison » :
            // le livreur doit toujours savoir qu'il a un montant à encaisser.
            $locked->update(['payment_status' => OrderPaymentStatus::Pending]
                + ($locked->payment_method === GatewayRegistry::CASH_ON_DELIVERY ? [] : ['payment_method' => $method]));
            $this->journal->record($locked, 'payment', null, null, ['event' => 'payment.initiated', 'provider' => $method, 'amount_xof' => $payment->amount_xof, 'payment_id' => $payment->id]);

            return $payment;
        });
        if ($result instanceof DomainException) {
            throw $result;
        }

        return $result;
    }

    /** Délai au-delà duquel une tentative restée sans session (processus interrompu) est abandonnée. */
    private const SESSION_CREATION_GRACE_MINUTES = 2;

    /**
     * Tentative « initiated » sans session : une autre requête est en train d'appeler le prestataire
     * (refus temporaire, jamais deux sessions), ou le processus a été interrompu (tentative close, null).
     * Toute autre tentative est renvoyée telle quelle.
     */
    private function settleUnstartedAttempt(Payment $payment): ?Payment
    {
        if ($payment->status !== PaymentStatus::Initiated || $payment->checkout_url !== null) {
            return $payment;
        }
        if ($payment->created_at->gt(now()->subMinutes(self::SESSION_CREATION_GRACE_MINUTES))) {
            throw DomainException::conflict('payment_starting', 'Le paiement est en cours d\'ouverture. Réessayez dans quelques secondes.');
        }
        $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'session_not_created']);

        return null;
    }

    /** La commande peut-elle être payée en ligne ? (à appeler sous verrou) */
    private function assertPayable(Order $locked): void
    {
        if ($error = $this->payabilityError($locked)) {
            throw $error;
        }
    }

    private function payabilityError(Order $locked): ?DomainException
    {
        return match (true) {
            ! in_array($locked->status, [OrderStatus::PendingConfirmation, OrderStatus::Accepted], true) => DomainException::conflict('order_not_payable', 'Cette commande ne peut pas être payée dans son état actuel.'),
            $locked->payment_status === OrderPaymentStatus::Paid => DomainException::conflict('already_paid', 'Cette commande est déjà payée.'),
            default => null,
        };
    }

    /**
     * Réception d'un webhook : authentification synchrone, déduplication, puis traitement asynchrone.
     *
     * @return array{status:string, event_id:?string}
     */
    public function receiveWebhook(string $provider, Request $request): array
    {
        $gateway = $this->registry->get($provider);
        try {
            $event = $gateway->validateWebhook($request);
        } catch (InvalidWebhookSignature $e) {
            Log::warning('payment.webhook.invalid_signature', ['provider' => $provider, 'ip' => $request->ip()]);
            throw new DomainException('invalid_signature', 'Signature du webhook invalide.', 401);
        }
        if ($event->eventId === '') {
            throw DomainException::unprocessable('invalid_event', 'Événement sans identifiant.');
        }

        $payment = $event->providerReference
            ? Payment::query()->where('provider', $provider)->where('provider_reference', $event->providerReference)->first()
            : null;

        // INSERT ... ON CONFLICT DO NOTHING : déduplication sans exception (n'interrompt aucune transaction englobante).
        $eventRowId = (string) Str::uuid();
        $inserted = PaymentEvent::query()->insertOrIgnore([
            'id' => $eventRowId,
            'payment_id' => $payment?->id,
            'provider' => $provider,
            'provider_event_id' => $event->eventId,
            'event_type' => $event->type,
            'payload_redacted' => json_encode($event->payloadRedacted),
            'processing_status' => $payment ? 'received' : 'orphan',
            'received_at' => now(),
        ]);
        if ($inserted === 0) {
            // Événement déjà reçu : réponse 2xx sans nouvelle écriture (T-008).
            return ['status' => 'duplicate', 'event_id' => $event->eventId];
        }

        if ($payment) {
            ProcessPaymentEvent::dispatch($eventRowId)->afterCommit();
        }

        return ['status' => $payment ? 'accepted' : 'orphan', 'event_id' => $event->eventId];
    }

    /** Traitement d'un événement : la vérification s'appuie sur la consultation serveur du prestataire. */
    public function processEvent(PaymentEvent $event): void
    {
        if ($event->processed_at !== null || $event->payment === null) {
            return;
        }
        try {
            $status = $this->registry->get($event->provider)->fetchStatus($event->payment);
        } catch (GatewayUnavailable $e) {
            $event->update(['processing_status' => 'retry', 'processing_note' => $e->getMessage()]);

            throw $e; // rejoué par la file d'attente
        }
        $note = $this->applyVerifiedStatus($event->payment, $status, "webhook:{$event->provider_event_id}");
        $event->update(['processing_status' => 'processed', 'processed_at' => now(), 'processing_note' => $note]);
    }

    /** Rapprochement serveur-à-serveur (retour navigateur, tâche planifiée, action back-office). */
    public function reconcile(Payment $payment): Payment
    {
        if ($payment->status->isFinal()) {
            return $payment;
        }
        try {
            $status = $this->registry->get($payment->provider)->fetchStatus($payment);
        } catch (GatewayUnavailable) {
            $payment->update(['last_checked_at' => now()]);

            return $payment;
        }
        // Audit B2 : une session que le prestataire annonce encore « en attente » n'est JAMAIS close localement,
        // même après son heure d'expiration : le client peut être en train de valider. Le prestataire signale
        // lui-même l'expiration (« expired »), appliquée ci-dessous.
        $this->applyVerifiedStatus($payment, $status, 'reconciliation');

        return $payment->fresh();
    }

    /** Applique un état vérifié auprès du prestataire, de façon idempotente et sous verrou. */
    public function applyVerifiedStatus(Payment $payment, GatewayStatus $status, string $source): string
    {
        return DB::transaction(function () use ($payment, $status, $source) {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $locked->update(['last_checked_at' => now(), 'provider_status' => $status->rawStatus]);

            // Succès confirmé par le prestataire sur une tentative close de notre côté (échec ou expiration
            // annoncés puis rectifiés) : l'argent a bien été prélevé, il doit être enregistré (audit B2).
            $lateSuccess = $locked->status->isFinal() && $locked->status !== PaymentStatus::Succeeded && $status->status === 'succeeded';
            if ($locked->status->isFinal() && ! $lateSuccess) {
                return 'already_final';
            }

            if ($status->status === 'succeeded') {
                if ($status->amount !== $locked->amount_xof || strtoupper((string) $status->currency) !== 'XOF') {
                    $this->journal->record($order, 'payment', null, null, ['event' => 'payment.amount_mismatch', 'payment_id' => $locked->id, 'expected' => $locked->amount_xof, 'received' => $status->amount, 'currency' => $status->currency]);
                    Log::error('payment.amount_mismatch', ['payment_id' => $locked->id]);
                    // Audit M5 : tentative close (jamais comptée comme encaissée), à examiner par l'équipe avec le
                    // prestataire ; elle n'est plus reprise par le rapprochement planifié.
                    $locked->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'amount_mismatch']);
                    $this->audit->log('payment.amount_mismatch', $locked, null, ['expected' => $locked->amount_xof, 'received' => $status->amount, 'currency' => $status->currency, 'source' => $source]);

                    return 'amount_mismatch';
                }
                $locked->update(['status' => PaymentStatus::Succeeded, 'verified_at' => now()]);
                $order->paid_xof += $locked->amount_xof;
                $order->payment_status = $order->paid_xof >= $order->total_xof ? OrderPaymentStatus::Paid : OrderPaymentStatus::Pending;
                $order->version++;
                $order->save();
                $event = $lateSuccess ? 'payment.late_success' : 'payment.confirmed';
                $this->journal->record($order, 'payment', null, null, ['event' => $event, 'payment_id' => $locked->id, 'amount_xof' => $locked->amount_xof, 'source' => $source]);
                $this->audit->log($event, $locked, null, ['amount_xof' => $locked->amount_xof, 'source' => $source], null);
                if ($lateSuccess) {
                    Log::warning('payment.late_success', ['payment_id' => $locked->id, 'order' => $order->reference]);
                }

                if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Rejected], true)) {
                    // Paiement tardif après annulation : remboursement à instruire (BR §6).
                    $this->refunds->requestForOrder($order, $locked->amount_xof, 'Paiement reçu après annulation/refus de la commande', null);
                } else {
                    $this->refundOverpayment($order);
                }
                DB::afterCommit(fn () => $this->notifier->paymentConfirmed($order->fresh()));
                DB::afterCommit(fn () => IssueInvoice::dispatch($order->id));

                return 'confirmed';
            }

            if (in_array($status->status, ['failed', 'cancelled', 'expired'], true)) {
                $locked->update(['status' => PaymentStatus::from($status->status), 'failure_reason' => $status->rawStatus]);
                $hasOther = Payment::query()->where('order_id', $order->id)->where('id', '!=', $locked->id)
                    ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Initiated->value])->exists();
                if ($order->payment_status === OrderPaymentStatus::Pending && ! $hasOther && $order->paid_xof === 0) {
                    $order->update(['payment_status' => OrderPaymentStatus::Failed]);
                }
                $this->journal->record($order, 'payment', null, null, ['event' => 'payment.failed', 'payment_id' => $locked->id, 'status' => $status->status, 'source' => $source]);

                return 'failed';
            }

            return 'still_pending';
        });
    }

    /**
     * Double encaissement (audit E2) : tout montant encaissé au-delà du total ouvre une demande de
     * remboursement du trop-perçu (double contrôle conservé), sans avoir puisque la vente est inchangée.
     * À appeler sous verrou de la commande.
     */
    private function refundOverpayment(Order $order): void
    {
        $alreadyHandled = (int) Refund::query()->where('order_id', $order->id)->where('kind', 'overpayment')
            ->whereIn('status', ['requested', 'approved', 'succeeded'])->sum('amount_xof');
        $excess = $order->paid_xof - $order->total_xof - $alreadyHandled;
        if ($excess <= 0) {
            return;
        }
        $this->journal->record($order, 'payment', null, null, ['event' => 'payment.overpaid', 'excess_xof' => $excess]);
        Log::warning('payment.overpaid', ['order' => $order->reference, 'excess_xof' => $excess]);
        $this->refunds->requestForOrder($order, $excess, "Trop-perçu : {$excess} FCFA encaissés au-delà du total de la commande", null, 'overpayment');
    }

    /** Une session de paiement en ligne est-elle encore ouverte (le client peut payer à tout moment) ? */
    public function hasOpenOnlineSession(Order $order): bool
    {
        return Payment::query()->where('order_id', $order->id)->where('is_manual', false)
            ->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])->exists();
    }

    /** Correction / encaissement manuel (espèces à la livraison, virement) : rôle habilité, motif, audit (§7). */
    public function recordManualPayment(Order $order, int $amount, string $method, string $reason, User $actor): Payment
    {
        if (! $actor->can('payments.manual')) {
            throw DomainException::forbidden();
        }

        return DB::transaction(function () use ($order, $amount, $method, $reason, $actor) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            // Audit M9 : pas d'encaissement sur une commande annulée, refusée ou brouillon.
            if (in_array($locked->status, [OrderStatus::Cancelled, OrderStatus::Rejected, OrderStatus::Draft], true)) {
                throw DomainException::conflict('order_not_payable', 'Cette commande n\'est pas en état d\'être encaissée.');
            }
            // Audit E2 : une session en ligne ouverte pourrait être payée en plus de l'encaissement manuel.
            if ($this->hasOpenOnlineSession($locked)) {
                throw DomainException::conflict('online_payment_in_progress', 'Un paiement en ligne est en cours pour cette commande : utilisez « Rapprocher » ou attendez son expiration avant d\'enregistrer un encaissement.');
            }
            $due = $locked->total_xof - $locked->paid_xof;
            if ($amount <= 0 || $amount > $due) {
                throw DomainException::unprocessable('invalid_amount', "Le montant doit être compris entre 1 et {$due} FCFA.");
            }
            $payment = Payment::query()->create([
                'order_id' => $locked->id,
                'provider' => 'manual:'.$method,
                'status' => PaymentStatus::Succeeded,
                'amount_xof' => $amount,
                'currency' => 'XOF',
                'idempotency_key' => 'manual-'.Str::uuid(),
                'is_manual' => true,
                'recorded_by' => $actor->id,
                'verified_at' => now(),
                'failure_reason' => null,
                'provider_status' => $reason,
            ]);
            $locked->paid_xof += $amount;
            $locked->payment_status = $locked->paid_xof >= $locked->total_xof ? OrderPaymentStatus::Paid : $locked->payment_status;
            $locked->version++;
            $locked->save();
            $this->journal->record($locked, 'payment', $actor, $reason, ['event' => 'payment.manual_recorded', 'payment_id' => $payment->id, 'amount_xof' => $amount, 'method' => $method]);
            $this->audit->log('payment.manual_recorded', $payment, null, ['amount_xof' => $amount, 'method' => $method, 'reason' => $reason]);
            DB::afterCommit(fn () => IssueInvoice::dispatch($locked->id));

            return $payment;
        });
    }
}
