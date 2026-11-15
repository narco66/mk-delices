<?php

namespace App\Domain\Payments;

use App\Domain\Orders\OrderJournal;
use App\Domain\Orders\OrderPaymentStatus;
use App\Domain\Payments\Gateways\GatewayUnavailable;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
    public function requestForOrder(Order $order, int $amount, string $reason, ?User $requester): Refund
    {
        $refundable = $order->paid_xof - $order->refunded_xof - $this->pendingAmount($order);
        if ($amount <= 0 || $amount > $refundable) {
            throw DomainException::unprocessable('refund_exceeds_captured', "Montant remboursable maximum : {$refundable} FCFA.", ['refundable_xof' => $refundable]);
        }
        $payment = Payment::query()->where('order_id', $order->id)->where('status', 'succeeded')->orderByDesc('amount_xof')->first();
        if (! $payment) {
            throw DomainException::unprocessable('no_captured_payment', 'Aucun paiement encaissé à rembourser.');
        }
        $refund = Refund::query()->create([
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_xof' => $amount,
            'status' => 'requested',
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

        return DB::transaction(function () use ($refund, $approver) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'requested') {
                throw DomainException::conflict('refund_not_pending', 'Ce remboursement a déjà été traité.');
            }
            $locked->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
            $this->audit->log('refund.approved', $locked, ['status' => 'requested'], ['status' => 'approved']);
            $this->journal->record($locked->order, 'refund', $approver, null, ['event' => 'refund.approved', 'refund_id' => $locked->id]);

            // Exécution automatique si le prestataire le permet ; sinon exécution manuelle puis « complete ».
            $payment = $locked->payment;
            if (! $payment->is_manual) {
                try {
                    $providerRef = app(GatewayRegistry::class)->get($payment->provider)->refund($locked, $payment);
                    if ($providerRef !== null) {
                        return $this->markCompleted($locked, $providerRef, $approver);
                    }
                } catch (GatewayUnavailable) {
                    // reste « approved » : exécution manuelle requise
                }
            }

            return $locked;
        });
    }

    public function reject(Refund $refund, User $approver, string $reason): Refund
    {
        if (! $approver->can('refunds.approve')) {
            throw DomainException::forbidden();
        }
        if ($refund->status !== 'requested') {
            throw DomainException::conflict('refund_not_pending', 'Ce remboursement a déjà été traité.');
        }
        $refund->update(['status' => 'rejected', 'approved_by' => $approver->id, 'approved_at' => now()]);
        $this->audit->log('refund.rejected', $refund, null, ['reason' => $reason]);
        $this->journal->record($refund->order, 'refund', $approver, $reason, ['event' => 'refund.rejected', 'refund_id' => $refund->id]);

        return $refund;
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

        return $refund;
    }
}
