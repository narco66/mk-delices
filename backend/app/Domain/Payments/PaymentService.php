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
use App\Jobs\ProcessPaymentEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
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

        return DB::transaction(function () use ($order, $method, $gateway, $idempotencyKey) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, [OrderStatus::PendingConfirmation, OrderStatus::Accepted], true)) {
                throw DomainException::conflict('order_not_payable', 'Cette commande ne peut pas être payée dans son état actuel.');
            }
            if ($locked->payment_status === OrderPaymentStatus::Paid) {
                throw DomainException::conflict('already_paid', 'Cette commande est déjà payée.');
            }
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->order_id !== $locked->id) {
                    throw DomainException::conflict('idempotency_key_reused', 'Clé d\'idempotence déjà utilisée.');
                }

                return $existing;
            }
            // Une seule session ouverte par commande et prestataire : on la réutilise (T-005).
            $open = Payment::query()->where('order_id', $locked->id)->where('provider', $method)
                ->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()->first();
            if ($open) {
                return $open;
            }

            $amount = $locked->total_xof - $locked->paid_xof;
            $payment = Payment::query()->create([
                'order_id' => $locked->id,
                'provider' => $method,
                'status' => PaymentStatus::Initiated,
                'amount_xof' => $amount,
                'currency' => 'XOF',
                'idempotency_key' => $idempotencyKey,
                'is_simulated' => $method === 'simulator',
            ]);
            try {
                $session = $gateway->createSession($payment, $locked);
            } catch (GatewayUnavailable $e) {
                throw new DomainException('psp_unavailable', 'Le service de paiement est momentanément indisponible. Votre commande est conservée.', 503);
            }
            $payment->update([
                'status' => PaymentStatus::Pending,
                'provider_reference' => $session->providerReference,
                'checkout_url' => $session->checkoutUrl,
                'expires_at' => $session->expiresAt,
            ]);
            $locked->update(['payment_status' => OrderPaymentStatus::Pending, 'payment_method' => $method]);
            $this->journal->record($locked, 'payment', null, null, ['event' => 'payment.initiated', 'provider' => $method, 'amount_xof' => $amount, 'payment_id' => $payment->id]);

            return $payment;
        });
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
        if ($status->status === 'pending' && $payment->expires_at && $payment->expires_at->isPast()) {
            $status = new GatewayStatus('expired', $status->amount, $status->currency, $status->rawStatus);
        }
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

            if ($locked->status->isFinal()) {
                return 'already_final';
            }

            if ($status->status === 'succeeded') {
                if ($status->amount !== $locked->amount_xof || strtoupper((string) $status->currency) !== 'XOF') {
                    $this->journal->record($order, 'payment', null, null, ['event' => 'payment.amount_mismatch', 'payment_id' => $locked->id, 'expected' => $locked->amount_xof, 'received' => $status->amount, 'currency' => $status->currency]);
                    Log::error('payment.amount_mismatch', ['payment_id' => $locked->id]);

                    return 'amount_mismatch';
                }
                $locked->update(['status' => PaymentStatus::Succeeded, 'verified_at' => now()]);
                $order->paid_xof += $locked->amount_xof;
                $order->payment_status = $order->paid_xof >= $order->total_xof ? OrderPaymentStatus::Paid : OrderPaymentStatus::Pending;
                $order->version++;
                $order->save();
                $this->journal->record($order, 'payment', null, null, ['event' => 'payment.confirmed', 'payment_id' => $locked->id, 'amount_xof' => $locked->amount_xof, 'source' => $source]);
                $this->audit->log('payment.confirmed', $locked, null, ['amount_xof' => $locked->amount_xof, 'source' => $source], null);

                if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Rejected], true)) {
                    // Paiement tardif après annulation : remboursement à instruire (BR §6).
                    $this->refunds->requestForOrder($order, $locked->amount_xof, 'Paiement reçu après annulation/refus de la commande', null);
                }
                DB::afterCommit(fn () => $this->notifier->paymentConfirmed($order->fresh()));

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

    /** Correction / encaissement manuel (espèces à la livraison, virement) : rôle habilité, motif, audit (§7). */
    public function recordManualPayment(Order $order, int $amount, string $method, string $reason, User $actor): Payment
    {
        if (! $actor->can('payments.manual')) {
            throw DomainException::forbidden();
        }

        return DB::transaction(function () use ($order, $amount, $method, $reason, $actor) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
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

            return $payment;
        });
    }
}
