<?php

namespace App\Domain\Deliveries;

use App\Domain\Orders\OrderJournal;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Missions de livraison : une seule mission active par commande (index unique partiel + verrou),
 * livreur actif et éligible à la zone (BR-008/009), preuve horodatée (BR-010), incidents tracés.
 */
class DeliveryService
{
    public const COURIER_ACTIONS = ['accept', 'refuse', 'pick_up', 'deliver', 'fail'];

    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly OrderJournal $journal,
        private readonly AuditLogger $audit,
        private readonly Settings $settings,
    ) {}

    public function assign(Order $order, DeliveryPartner $partner, User $actor): DeliveryAssignment
    {
        if (! $actor->can('deliveries.assign')) {
            throw DomainException::forbidden();
        }
        try {
            return DB::transaction(function () use ($order, $partner, $actor) {
                /** @var Order $locked */
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                if (! in_array($locked->status, [OrderStatus::Ready, OrderStatus::DeliveryFailed], true)) {
                    throw DomainException::conflict('order_not_ready', 'Seule une commande prête (ou en échec de livraison) peut être affectée.');
                }
                if ($locked->fulfillment_type !== 'delivery') {
                    throw DomainException::conflict('not_delivery', 'Cette commande est à retirer sur place.');
                }
                if ($locked->activeAssignment()->exists()) {
                    throw DomainException::conflict('assignment_exists', 'Une mission est déjà active pour cette commande.');
                }
                $partner = DeliveryPartner::query()->lockForUpdate()->findOrFail($partner->id);
                if (! $partner->active || ! $partner->user?->is_active) {
                    throw DomainException::unprocessable('partner_inactive', 'Ce livreur n\'est pas actif.');
                }
                if ($locked->zone_id && ! $partner->zones()->whereKey($locked->zone_id)->exists()) {
                    throw DomainException::unprocessable('partner_not_eligible', 'Ce livreur ne dessert pas la zone de la commande.');
                }
                $cashDue = $locked->payment_method === GatewayRegistry::CASH_ON_DELIVERY ? max(0, $locked->total_xof - $locked->paid_xof) : 0;
                $assignment = DeliveryAssignment::query()->create([
                    'order_id' => $locked->id,
                    'partner_id' => $partner->id,
                    'assigned_by' => $actor->id,
                    'status' => AssignmentStatus::Proposed,
                    'partner_fee_xof' => (int) ($locked->zone?->partner_fee_xof ?? 0),
                    'cash_to_collect_xof' => $cashDue,
                    'assigned_at' => now(),
                ]);
                $this->journal->record($locked, 'delivery', $actor, null, ['event' => 'delivery.proposed', 'assignment_id' => $assignment->id, 'partner' => $partner->display_name]);
                $this->audit->log('delivery.assigned', $assignment, null, ['order' => $locked->reference, 'partner_id' => $partner->id]);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            // Affectation concurrente : l'index unique partiel garantit une seule mission active (T-012).
            throw DomainException::conflict('assignment_exists', 'Une mission est déjà active pour cette commande.');
        }
    }

    /** Retrait d'une mission (réaffectation) par le responsable, avant enlèvement uniquement. */
    public function cancel(DeliveryAssignment $assignment, User $actor, string $reason): DeliveryAssignment
    {
        if (! $actor->can('deliveries.assign')) {
            throw DomainException::forbidden();
        }

        return DB::transaction(function () use ($assignment, $actor, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($assignment->order_id);
            $locked = DeliveryAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (! in_array($locked->status, [AssignmentStatus::Proposed, AssignmentStatus::Accepted], true)) {
                throw DomainException::conflict('assignment_not_cancellable', 'Une mission enlevée ou clôturée ne peut pas être retirée.');
            }
            $wasAccepted = $locked->status === AssignmentStatus::Accepted;
            $locked->update(['status' => AssignmentStatus::Cancelled, 'closed_at' => now(), 'cancel_reason' => $reason]);
            if ($wasAccepted && $order->status === OrderStatus::Assigned) {
                $this->orders->system($order, OrderStatus::Ready, $actor, $reason, ['event' => 'delivery.reassign']);
            }
            $this->journal->record($order, 'delivery', $actor, $reason, ['event' => 'delivery.cancelled', 'assignment_id' => $locked->id]);

            return $locked;
        });
    }

    public function courierAction(DeliveryAssignment $assignment, User $courier, string $action, array $data = []): DeliveryAssignment
    {
        if (! in_array($action, self::COURIER_ACTIONS, true)) {
            throw DomainException::unprocessable('unknown_action', 'Action inconnue.');
        }

        return DB::transaction(function () use ($assignment, $courier, $action, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($assignment->order_id);
            /** @var DeliveryAssignment $mission */
            $mission = DeliveryAssignment::query()->with('partner')->lockForUpdate()->findOrFail($assignment->id);
            if ($mission->partner->user_id !== $courier->id) {
                throw DomainException::forbidden('Cette mission ne vous est pas affectée.');
            }
            $expected = [
                'accept' => AssignmentStatus::Proposed, 'refuse' => AssignmentStatus::Proposed,
                'pick_up' => AssignmentStatus::Accepted, 'deliver' => AssignmentStatus::PickedUp, 'fail' => AssignmentStatus::PickedUp,
            ][$action];
            if ($mission->status !== $expected) {
                throw DomainException::conflict('invalid_transition', "Action impossible : la mission est « {$mission->status->label()} ».");
            }

            match ($action) {
                'accept' => $this->accept($mission, $order, $courier),
                'refuse' => $this->refuse($mission, $order, $courier, $data),
                'pick_up' => $this->pickUp($mission, $order, $courier, $data),
                'deliver' => $this->deliver($mission, $order, $courier, $data),
                'fail' => $this->fail($mission, $order, $courier, $data),
            };

            return $mission->fresh(['order', 'partner']);
        });
    }

    /** Courses donnant lieu à rémunération : livrées, ou échouées avec une part due (échec imputable au client). */
    public static function payable(Builder $query): Builder
    {
        return $query->whereIn('status', [AssignmentStatus::Delivered->value, AssignmentStatus::Failed->value])
            ->where('partner_fee_xof', '>', 0);
    }

    /** Règlement des montants dus aux livreurs. */
    public function settle(array $assignmentIds, User $actor): int
    {
        if (! $actor->can('deliveries.settle')) {
            throw DomainException::forbidden();
        }
        $count = self::payable(DeliveryAssignment::query()->whereIn('id', $assignmentIds))->whereNull('settled_at')
            ->update(['settled_at' => now(), 'settled_by' => $actor->id]);
        $this->audit->log('delivery.settled', null, null, ['assignment_ids' => $assignmentIds, 'count' => $count]);

        return $count;
    }

    private function accept(DeliveryAssignment $mission, Order $order, User $courier): void
    {
        $mission->update(['status' => AssignmentStatus::Accepted, 'accepted_at' => now()]);
        $this->orders->system($order, OrderStatus::Assigned, $courier, null, ['event' => 'delivery.accepted', 'assignment_id' => $mission->id]);
    }

    private function refuse(DeliveryAssignment $mission, Order $order, User $courier, array $data): void
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw DomainException::unprocessable('reason_required', 'Indiquez le motif du refus.');
        }
        $mission->update(['status' => AssignmentStatus::Refused, 'refused_at' => now(), 'refusal_reason' => $reason, 'closed_at' => now()]);
        $this->journal->record($order, 'delivery', $courier, $reason, ['event' => 'delivery.refused', 'assignment_id' => $mission->id]);
    }

    private function pickUp(DeliveryAssignment $mission, Order $order, User $courier, array $data): void
    {
        if (empty($data['packaging_checked'])) {
            throw DomainException::unprocessable('packaging_check_required', 'Confirmez le contrôle du conditionnement à l\'enlèvement.');
        }
        $mission->update(['status' => AssignmentStatus::PickedUp, 'picked_up_at' => now(), 'packaging_checked' => true]);
        $this->orders->system($order, OrderStatus::PickedUp, $courier, null, ['event' => 'delivery.picked_up', 'assignment_id' => $mission->id]);
    }

    private function deliver(DeliveryAssignment $mission, Order $order, User $courier, array $data): void
    {
        $proofTypes = (array) $this->settings->get('delivery.proof_types', []);
        if (! in_array($data['proof_type'] ?? null, $proofTypes, true) || trim((string) ($data['proof_reference'] ?? '')) === '') {
            throw DomainException::unprocessable('proof_required', 'Une preuve de remise valide est obligatoire.', ['proof_types' => $proofTypes]);
        }
        $cash = null;
        if ($mission->cash_to_collect_xof > 0) {
            $cash = isset($data['cash_collected_xof']) ? (int) $data['cash_collected_xof'] : null;
            if ($cash === null || $cash < 0) {
                throw DomainException::unprocessable('cash_amount_required', 'Indiquez le montant encaissé auprès du client.');
            }
        }
        $mission->update([
            'status' => AssignmentStatus::Delivered,
            'delivered_at' => now(),
            'closed_at' => now(),
            'proof_type' => $data['proof_type'],
            'proof_reference' => mb_substr((string) $data['proof_reference'], 0, 255),
            'cash_collected_xof' => $cash,
        ]);
        $this->orders->system($order, OrderStatus::Delivered, $courier, null, ['event' => 'delivery.delivered', 'assignment_id' => $mission->id, 'proof_type' => $data['proof_type']]);
    }

    private function fail(DeliveryAssignment $mission, Order $order, User $courier, array $data): void
    {
        $reasons = (array) $this->settings->get('delivery.incident_reasons', []);
        if (! in_array($data['incident_reason'] ?? null, $reasons, true)) {
            throw DomainException::unprocessable('incident_reason_required', 'Choisissez le motif de l\'échec.', ['reasons' => $reasons]);
        }
        // Course échouée : rémunérée à X % si l'échec est dû au client (règle validée le 15/11/2026), sinon non due.
        $clientFault = in_array($data['incident_reason'], (array) $this->settings->get('delivery.client_fault_reasons', []), true);
        $percent = $clientFault ? max(0, min(100, (int) $this->settings->get('delivery.failed_fee_percent', 0))) : 0;
        $fee = intdiv($mission->partner_fee_xof * $percent, 100);
        $mission->update([
            'status' => AssignmentStatus::Failed,
            'failed_at' => now(),
            'closed_at' => now(),
            'incident_reason' => $data['incident_reason'],
            'incident_note' => $data['incident_note'] ?? null,
            'product_condition' => $data['product_condition'] ?? null,
            'contact_attempted' => (bool) ($data['contact_attempted'] ?? false),
            'partner_fee_xof' => $fee,
        ]);
        $this->orders->system($order, OrderStatus::DeliveryFailed, $courier, $data['incident_note'] ?? $data['incident_reason'], [
            'event' => 'delivery.failed', 'assignment_id' => $mission->id, 'incident_reason' => $data['incident_reason'],
            'product_condition' => $data['product_condition'] ?? null, 'contact_attempted' => (bool) ($data['contact_attempted'] ?? false),
            'client_fault' => $clientFault, 'partner_fee_percent' => $percent, 'partner_fee_xof' => $fee,
        ]);
    }
}
