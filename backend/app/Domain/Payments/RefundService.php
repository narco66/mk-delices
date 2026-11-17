<?php

namespace App\Domain\Payments;

use App\Domain\Orders\OrderJournal;
use App\Domain\Orders\OrderPaymentStatus;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Jobs\IssueInvoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remboursements sous double contrôle (SEC-014) : le demandeur ne peut pas approuver sa propre demande.
 * Montant cumulé remboursé ≤ montant réellement capturé (BR-013).
 */
class RefundService
{
    public function __construct(private readonly AuditLogger $audit, private readonly OrderJournal $journal) {}

    public function pendingAmount(Order $order): int
    {
        return (int) Refund::query()->where('order_id', $order->id)->whereIn('status', ['requested', 'approved'])->sum('amount_xof');
    }

    /** À appeler dans une transaction avec la commande verrouillée. */
    /** @param  string  $kind  « sale » (réduction de la vente, avoir) ou « overpayment » (trop-perçu, sans avoir) */
    public function requestForOrder(Order $order, int $amount, string $reason, ?User $requester, string $kind = 'sale'): Refund
    {
        $refundable = $order->paid_xof - $order->refunded_xof - $this->pendingAmount($order);
        if ($amount <= 0 || $amount > $refundable) {
            throw DomainException::unprocessable('refund_exceeds_captured', "Montant remboursable maximum : {$refundable} FCFA.", ['refundable_xof' => $refundable]);
        }
        // Audit F3 : imputer sur un paiement dont le solde remboursable couvre le montant (sinon le plus gros :
        // l'exécution automatique sera alors refusée et le remboursement fait manuellement).
        $payments = Payment::query()->where('order_id', $order->id)->where('status', 'succeeded')->orderByDesc('amount_xof')->get();
        $payment = $payments->first(fn (Payment $p) => $this->remainingOnPayment($p) >= $amount) ?? $payments->first();
        if (! $payment) {
            throw DomainException::unprocessable('no_captured_payment', 'Aucun paiement encaissé à rembourser.');
        }
        $refund = Refund::query()->create([
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_xof' => $amount,
            'status' => 'requested',
            'kind' => $kind,
            'reason' => $reason,
            'requested_by' => $requester?->id,
        ]);
        $this->journal->record($order, 'refund', $requester, $reason, ['event' => 'refund.requested', 'refund_id' => $refund->id, 'amount_xof' => $amount]);
        $this->audit->log('refund.requested', $refund, null, ['amount_xof' => $amount, 'reason' => $reason], $requester?->id);

        return $refund;
    }

    public function approve(Refund $refund, User $approver): Refund
    {
        if (! $approver->can('refunds.approve')) {
            throw DomainException::forbidden();
        }
        if ($refund->requested_by !== null && $refund->requested_by === $approver->id) {
            throw DomainException::forbidden('Double contrôle : la personne qui a demandé le remboursement ne peut pas l\'approuver.');
        }

        // 1. Approbation, dans une transaction courte (aucun appel externe sous verrou — audit B1).
        $approved = DB::transaction(function () use ($refund, $approver) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'requested') {
                throw DomainException::conflict('refund_not_pending', 'Ce remboursement a déjà été traité.');
            }
            $locked->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
            $this->audit->log('refund.approved', $locked, ['status' => 'requested'], ['status' => 'approved']);
            $this->journal->record($locked->order, 'refund', $approver, null, ['event' => 'refund.approved', 'refund_id' => $locked->id]);

            return $locked;
        });

        // 2. Exécution automatique seulement si elle est sûre ; sinon exécution manuelle puis « complete ».
        $payment = $approved->payment;
        if ($blocker = $this->autoExecutionBlocker($approved, $payment)) {
            $approved->update(['execution_note' => $blocker]);

            return $approved->fresh();
        }
        // Marqueur posé AVANT l'appel : un second déclenchement ne rappelle jamais le prestataire.
        $claimed = Refund::query()->whereKey($approved->id)->where('status', 'approved')->whereNull('auto_execution_started_at')
            ->update(['auto_execution_started_at' => now()]);
        if ($claimed !== 1) {
            return $approved->fresh();
        }
        try {
            $providerRef = app(GatewayRegistry::class)->get($payment->provider)->refund($approved, $payment);
        } catch (\Throwable $e) {
            // Délai dépassé, réseau, refus : le remboursement a PEUT-ÊTRE eu lieu. Pas de nouvel essai automatique.
            Log::error('refund.auto_execution_uncertain', ['refund_id' => $approved->id, 'error' => $e->getMessage()]);
            $approved->update(['execution_note' => 'Exécution automatique non confirmée ('.class_basename($e).') : vérifier chez le prestataire, puis « Confirmer l\'exécution » avec sa référence.']);

            return $approved->fresh();
        }
        if ($providerRef === null) {
            $approved->update(['execution_note' => 'Exécution manuelle requise chez le prestataire, puis « Confirmer l\'exécution ».']);

            return $approved->fresh();
        }

        return DB::transaction(fn () => $this->markCompleted(Refund::query()->lockForUpdate()->findOrFail($approved->id), $providerRef, $approver));
    }

    /** Montant encore remboursable sur un paiement (remboursements effectués ou en cours d'exécution déduits). */
    public function remainingOnPayment(Payment $payment, ?string $exceptRefundId = null): int
    {
        $used = (int) Refund::query()->where('payment_id', $payment->id)
            ->when($exceptRefundId, fn ($q) => $q->where('id', '!=', $exceptRefundId))
            ->where(fn ($q) => $q->where('status', 'succeeded')->orWhere(fn ($a) => $a->where('status', 'approved')->whereNotNull('auto_execution_started_at')))
            ->sum('amount_xof');

        return $payment->amount_xof - $used;
    }

    /** Raison pour laquelle le remboursement ne peut pas être exécuté automatiquement (null : exécution sûre). */
    private function autoExecutionBlocker(Refund $refund, Payment $payment): ?string
    {
        if ($payment->is_manual) {
            return 'Paiement encaissé hors ligne : rembourser le client directement, puis « Confirmer l\'exécution ».';
        }
        if ($refund->amount_xof > $this->remainingOnPayment($payment, $refund->id)) {
            return 'Montant supérieur au solde remboursable de ce paiement : exécution manuelle requise.';
        }
        if ($refund->amount_xof !== $payment->amount_xof && ! app(GatewayRegistry::class)->get($payment->provider)->supportsPartialRefund()) {
            return 'Remboursement partiel non pris en charge automatiquement par ce prestataire : l\'effectuer depuis son espace marchand, puis « Confirmer l\'exécution ».';
        }

        return null;
    }

    public function reject(Refund $refund, User $approver, string $reason): Refund
    {
        if (! $approver->can('refunds.approve')) {
            throw DomainException::forbidden();
        }

        // Verrou + relecture (audit E3) : une approbation concurrente en cours d'exécution ne peut pas être écrasée.
        return DB::transaction(function () use ($refund, $approver, $reason) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'requested') {
                throw DomainException::conflict('refund_not_pending', 'Ce remboursement a déjà été traité.');
            }
            $locked->update(['status' => 'rejected', 'approved_by' => $approver->id, 'approved_at' => now()]);
            $this->audit->log('refund.rejected', $locked, null, ['reason' => $reason]);
            $this->journal->record($locked->order, 'refund', $approver, $reason, ['event' => 'refund.rejected', 'refund_id' => $locked->id]);

            return $locked;
        });
    }

    /** Confirme l'exécution effective d'un remboursement approuvé (référence prestataire ou pièce). */
    public function complete(Refund $refund, string $providerRef, User $actor): Refund
    {
        if (! $actor->can('refunds.approve')) {
            throw DomainException::forbidden();
        }

        return DB::transaction(function () use ($refund, $providerRef, $actor) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'approved') {
                throw DomainException::conflict('refund_not_approved', 'Seul un remboursement approuvé peut être finalisé.');
            }

            return $this->markCompleted($locked, $providerRef, $actor);
        });
    }

    private function markCompleted(Refund $refund, string $providerRef, User $actor): Refund
    {
        /** @var Order $order */
        $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
        if ($order->refunded_xof + $refund->amount_xof > $order->paid_xof) {
            throw DomainException::conflict('refund_exceeds_captured', 'Le remboursement dépasserait le montant encaissé.');
        }
        $refund->update(['status' => 'succeeded', 'provider_ref' => $providerRef, 'completed_at' => now()]);
        $order->refunded_xof += $refund->amount_xof;
        $order->payment_status = $order->refunded_xof >= $order->paid_xof ? OrderPaymentStatus::Refunded : OrderPaymentStatus::PartiallyRefunded;
        $order->version++;
        $order->save();
        $this->journal->record($order, 'refund', $actor, null, ['event' => 'refund.completed', 'refund_id' => $refund->id, 'amount_xof' => $refund->amount_xof]);
        $this->audit->log('refund.completed', $refund, null, ['provider_ref' => $providerRef]);
        // Commande déjà facturée : l'avoir correspondant est émis automatiquement.
        DB::afterCommit(fn () => IssueInvoice::dispatch($order->id, $refund->id));

        return $refund;
    }
}
