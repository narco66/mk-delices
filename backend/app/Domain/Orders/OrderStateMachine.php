<?php

namespace App\Domain\Orders;

use App\Domain\Payments\RefundService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Jobs\IssueInvoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Machine à états des commandes (docs/07-BUSINESS-RULES §2-3).
 * Aucune modification de statut ne doit contourner cette classe (invariant §7).
 */
class OrderStateMachine
{
    /**
     * Actions manuelles : action => [états de départ, état d'arrivée, permission, motif obligatoire].
     */
    public const ACTIONS = [
        'submit' => [['draft'], 'pending_confirmation', 'orders.manage', false],
        'accept' => [['pending_confirmation'], 'accepted', 'orders.manage', false],
        'reject' => [['pending_confirmation'], 'rejected', 'orders.manage', true],
        'cancel' => [['draft', 'pending_confirmation', 'accepted', 'preparing', 'delivery_failed'], 'cancelled', 'orders.manage', true],
        'start_preparation' => [['accepted'], 'preparing', 'preparation.manage', false],
        // Correction d'un démarrage par erreur : limitée dans le temps, motif obligatoire, tracée.
        'cancel_preparation' => [['preparing'], 'accepted', 'preparation.manage', true],
        'mark_ready' => [['preparing'], 'ready', 'preparation.manage', false],
        'hand_over_pickup' => [['ready'], 'delivered', 'orders.manage', false],
    ];

    /** Transitions pilotées par le module livraison (non exposées comme actions manuelles). */
    private const SYSTEM_TRANSITIONS = [
        'ready' => ['assigned'],
        'delivery_failed' => ['assigned'],
        'assigned' => ['picked_up', 'ready'],
        'picked_up' => ['delivered', 'delivery_failed'],
    ];

    private const ONLINE_METHODS = ['wave', 'orange_money', 'card', 'simulator'];

    public function __construct(
        private readonly StockService $stock,
        private readonly RefundService $refunds,
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
        private readonly OrderJournal $journal,
    ) {}

    /** Actions manuelles possibles pour cet utilisateur sur cette commande (pour l'UI). */
    public function availableActions(Order $order, User $user): array
    {
        $actions = [];
        foreach (self::ACTIONS as $name => [$from, , $permission]) {
            if (! in_array($order->status->value, $from, true) || ! $user->can($permission)) {
                continue;
            }
            if ($name === 'hand_over_pickup' && $order->fulfillment_type !== 'pickup') {
                continue;
            }
            if ($name === 'cancel_preparation' && ! $this->withinUndoWindow($order)) {
                continue;
            }
            $actions[] = $name;
        }

        return $actions;
    }

    public function apply(Order $order, string $action, User $actor, ?string $reason = null, ?int $expectedVersion = null, array $context = []): Order
    {
        if (! isset(self::ACTIONS[$action])) {
            throw DomainException::unprocessable('unknown_action', "Action inconnue : {$action}");
        }
        [$from, $to, $permission, $reasonRequired] = self::ACTIONS[$action];
        if (! $actor->can($permission)) {
            throw DomainException::forbidden();
        }
        if ($reasonRequired && trim((string) $reason) === '') {
            throw DomainException::unprocessable('reason_required', 'Un motif est obligatoire pour cette action.');
        }

        return DB::transaction(function () use ($order, $action, $actor, $reason, $expectedVersion, $from, $to, $context) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
                throw DomainException::conflict('version_conflict', 'La commande a été modifiée entre-temps. Rechargez la page.', ['current_version' => $locked->version]);
            }
            if (! in_array($locked->status->value, $from, true)) {
                throw DomainException::conflict('invalid_transition', "Transition « {$action} » impossible depuis l'état « {$locked->status->label()} ».", ['status' => $locked->status->value]);
            }
            $this->guard($locked, $action, $context);

            $previous = $locked->status;
            match ($action) {
                'submit' => $this->stock->reserveForOrder($locked),
                'reject', 'cancel' => $this->onTermination($locked, $actor, (string) $reason),
                default => null,
            };

            $this->move($locked, OrderStatus::from($to), $actor, $reason, $context + ['action' => $action]);
            $this->audit->log("order.{$action}", $locked, ['status' => $previous->value], ['status' => $to, 'reason' => $reason]);

            return $locked;
        });
    }

    /** Transition déclenchée par un autre module (livraison). Doit être appelée dans une transaction avec la commande verrouillée. */
    public function system(Order $lockedOrder, OrderStatus $to, ?User $actor, ?string $reason = null, array $metadata = []): void
    {
        $allowed = self::SYSTEM_TRANSITIONS[$lockedOrder->status->value] ?? [];
        if (! in_array($to->value, $allowed, true)) {
            throw DomainException::conflict('invalid_transition', "Transition impossible de « {$lockedOrder->status->label()} » vers « {$to->label()} ».");
        }
        $this->move($lockedOrder, $to, $actor, $reason, $metadata);
    }

    private function move(Order $order, OrderStatus $to, ?User $actor, ?string $reason, array $metadata): void
    {
        $from = $order->status;
        $order->status = $to;
        $order->version++;
        $undo = $from === OrderStatus::Preparing && $to === OrderStatus::Accepted;
        $timestamp = $undo ? null : match ($to) {
            OrderStatus::PendingConfirmation => 'confirmed_at',
            OrderStatus::Accepted => 'accepted_at',
            OrderStatus::Preparing => 'preparing_at',
            OrderStatus::Ready => 'ready_at',
            OrderStatus::PickedUp => 'picked_up_at',
            OrderStatus::Delivered => 'delivered_at',
            default => null,
        };
        if ($timestamp) {
            $order->{$timestamp} = now();
        }
        if ($undo) {
            // Démarrage annulé : la commande redevient modifiable, comme avant la préparation.
            $order->preparing_at = null;
            $order->details_locked = false;
        }
        if ($to === OrderStatus::Preparing) {
            // Destinataire, téléphone, créneau et consignes gelés au passage en préparation (PRD §7).
            $order->details_locked = true;
        }
        if ($to->isFinal()) {
            $order->closed_at = now();
        }
        $order->save();

        $this->journal->record($order, 'status', $actor, $reason, $metadata, $from->value, $to->value);
        // L'annulation d'un démarrage est une correction interne : ni le client ni l'équipe ne sont renotifiés.
        if (! $undo) {
            DB::afterCommit(fn () => $this->notifier->orderStatusChanged($order->fresh(), $to));
        }
        if ($to === OrderStatus::Delivered) {
            DB::afterCommit(fn () => IssueInvoice::dispatch($order->id));
        }
    }

    private function guard(Order $order, string $action, array $context): void
    {
        if ($action === 'accept' && $this->settings->get('payments.require_payment_before_acceptance', true)
            && in_array($order->payment_method, self::ONLINE_METHODS, true)
            && $order->payment_status !== OrderPaymentStatus::Paid) {
            throw DomainException::conflict('payment_required', 'Le paiement en ligne n\'est pas encore confirmé par le prestataire.');
        }
        if ($action === 'mark_ready' && empty($context['packaging_checked'])) {
            throw DomainException::unprocessable('packaging_check_required', 'Le contrôle du conditionnement doit être confirmé avant de marquer la commande prête.');
        }
        if ($action === 'cancel_preparation' && ! $this->withinUndoWindow($order)) {
            throw DomainException::conflict('undo_window_expired', 'Le démarrage ne peut plus être annulé : le délai de correction est dépassé. Contactez le commercial si la commande doit être annulée.');
        }
        if ($action === 'hand_over_pickup' && $order->fulfillment_type !== 'pickup') {
            throw DomainException::conflict('not_pickup', 'Cette commande doit être livrée par un livreur.');
        }
        if ($action === 'submit' && ! $order->items()->exists()) {
            throw DomainException::unprocessable('cart_empty', 'La commande ne contient aucun article.');
        }
    }

    /** Délai pendant lequel un démarrage de préparation peut être annulé (paramètre preparation.undo_window_minutes). */
    public function withinUndoWindow(Order $order): bool
    {
        $minutes = (int) $this->settings->get('preparation.undo_window_minutes', 15);

        return $order->status === OrderStatus::Preparing && $order->preparing_at !== null && $minutes > 0
            && $order->preparing_at->greaterThan(now()->subMinutes($minutes));
    }

    /** Annulation à l'initiative du client (lien de suivi), uniquement avant acceptation commerciale. */
    public function cancelByCustomer(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== OrderStatus::PendingConfirmation) {
                throw DomainException::conflict('cancellation_not_allowed', 'Votre commande est déjà prise en charge : contactez MK Délices pour toute annulation.');
            }
            $this->onTermination($locked, null, 'Annulation client : '.$reason);
            $this->move($locked, OrderStatus::Cancelled, null, 'Annulation client : '.$reason, ['action' => 'customer_cancel']);
            $this->audit->log('order.customer_cancel', $locked, ['status' => 'pending_confirmation'], ['status' => 'cancelled']);

            return $locked;
        });
    }

    private function onTermination(Order $order, ?User $actor, string $reason): void
    {
        // Le stock n'est restitué que si la production n'a pas commencé (produits périssables).
        if (in_array($order->status, [OrderStatus::PendingConfirmation, OrderStatus::Accepted], true)) {
            $this->stock->releaseForOrder($order);
        }
        // Une commande refusée/annulée après encaissement ouvre un remboursement explicite (BR-006).
        $refundable = $order->paid_xof - $order->refunded_xof - $this->refunds->pendingAmount($order);
        if ($refundable > 0) {
            $this->refunds->requestForOrder($order, $refundable, "Commande {$order->reference} : {$reason}", $actor);
        }
        if ($assignment = $order->activeAssignment()->lockForUpdate()->first()) {
            $assignment->update(['status' => 'cancelled', 'closed_at' => now(), 'cancel_reason' => $reason]);
        }
    }
}
