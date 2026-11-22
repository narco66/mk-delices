<?php

namespace App\Domain\Deliveries;

use App\Domain\Customers\Push\MobilePushNotifier;
use App\Domain\Deliveries\Push\CourierPushNotifier;
use App\Domain\Orders\OrderJournal;
use App\Domain\Orders\OrderPaymentStatus;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Jobs\NotifyCourierOfMission;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryPartner;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\StaffOrderActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Missions de livraison : une seule mission active par commande (index unique partiel + verrou),
 * livreur actif et éligible à la zone (BR-008/009), preuve horodatée (BR-010), incidents tracés.
 */
class DeliveryService
{
    public const COURIER_ACTIONS = ['accept', 'refuse', 'release', 'pick_up', 'deliver', 'fail'];

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
                // Audit espace livreur E2 : un livreur qui s'est déclaré indisponible ne reçoit pas de mission.
                if (! $partner->available) {
                    throw DomainException::unprocessable('partner_unavailable', "{$partner->display_name} s'est déclaré indisponible : choisissez un autre livreur.");
                }
                if ($locked->zone_id && ! $partner->zones()->whereKey($locked->zone_id)->exists()) {
                    throw DomainException::unprocessable('partner_not_eligible', 'Ce livreur ne dessert pas la zone de la commande.');
                }
                // Reste dû à encaisser à la remise : tout ce qui n'est pas encore payé, quel que soit le moyen noté
                // (audit du 22/11/2026, A-01). Une tentative de paiement en ligne abandonnée sur une commande saisie
                // par l'équipe change le moyen noté, mais ne doit jamais faire partir une commande sans encaissement.
                $cashDue = $locked->payment_status === OrderPaymentStatus::Paid ? 0 : max(0, $locked->total_xof - $locked->paid_xof);
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
                // Notification du navigateur au livreur (s'il l'a activée), seulement si l'affectation est validée.
                if (CourierPushNotifier::enabled() || app(MobilePushNotifier::class)->enabled()) {
                    DB::afterCommit(fn () => NotifyCourierOfMission::dispatch($assignment->id));
                }

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
                'accept' => AssignmentStatus::Proposed, 'refuse' => AssignmentStatus::Proposed, 'release' => AssignmentStatus::Accepted,
                'pick_up' => AssignmentStatus::Accepted, 'deliver' => AssignmentStatus::PickedUp, 'fail' => AssignmentStatus::PickedUp,
            ][$action];
            if ($mission->status !== $expected) {
                throw DomainException::conflict('invalid_transition', "Action impossible : la mission est « {$mission->status->label()} ».");
            }

            match ($action) {
                'accept' => $this->accept($mission, $order, $courier),
                'refuse' => $this->refuse($mission, $order, $courier, $data),
                'release' => $this->release($mission, $order, $courier, $data),
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
        // À régler : une rémunération due OU des espèces encaissées à remettre (audit M8), même si la course
        // est gratuite pour le livreur — sinon ces espèces resteraient « détenues » indéfiniment.
        return $query->whereIn('status', [AssignmentStatus::Delivered->value, AssignmentStatus::Failed->value])
            ->where(fn (Builder $q) => $q->where('partner_fee_xof', '>', 0)->orWhere('cash_collected_xof', '>', 0));
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

    /**
     * Audit espace livreur M1 : le livreur rend une mission acceptée mais pas encore enlevée (panne, urgence).
     * La commande redevient « prête » : le responsable des livraisons est notifié et peut réaffecter.
     */
    private function release(DeliveryAssignment $mission, Order $order, User $courier, array $data): void
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw DomainException::unprocessable('reason_required', 'Indiquez pourquoi vous ne pouvez plus assurer la mission.');
        }
        $mission->update(['status' => AssignmentStatus::Cancelled, 'closed_at' => now(), 'cancel_reason' => mb_substr('Rendue par le livreur : '.$reason, 0, 300)]);
        $this->orders->system($order, OrderStatus::Ready, $courier, $reason, ['event' => 'delivery.released', 'assignment_id' => $mission->id]);
        $this->journal->record($order, 'delivery', $courier, $reason, ['event' => 'delivery.released', 'assignment_id' => $mission->id]);
    }

    /**
     * Audit espace livreur E1 : missions proposées et restées sans réponse au-delà du délai paramétré.
     * Le responsable des livraisons est alerté UNE fois par mission (anti-doublon du journal de notifications).
     *
     * @return int nombre d'alertes envoyées
     */
    public function alertUnacceptedProposals(): int
    {
        $minutes = (int) $this->settings->get('delivery.accept_timeout_minutes', 10);
        if ($minutes <= 0) {
            return 0;
        }
        $stale = DeliveryAssignment::query()->forRealOrders()->with(['order', 'partner'])
            ->where('status', AssignmentStatus::Proposed->value)
            ->where('assigned_at', '<', now()->subMinutes($minutes))->get();
        $sent = 0;
        foreach ($stale as $mission) {
            $claimed = NotificationLog::query()->insertOrIgnore([
                'id' => (string) Str::uuid(), 'order_id' => $mission->order_id, 'event' => 'staff:delivery.not_accepted',
                'channel' => 'database', 'dedupe_key' => "assignment:{$mission->id}:not_accepted", 'delivery_status' => 'sent',
                'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($claimed === 1) {
                Notification::send(User::role([User::ROLE_RESP_LIVRAISON])->where('is_active', true)->get(), new StaffOrderActivity($mission->order, 'delivery.not_accepted'));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Seuil sanitaire de la chaîne du froid (D-QUA-01, paramètre quality.cold_chain_max_minutes) : durée maximale
     * entre l'enlèvement à l'atelier et la remise au client. Null = contrôle désactivé (seuil non fixé).
     */
    public function coldChainMaxMinutes(): ?int
    {
        $minutes = (int) $this->settings->get('quality.cold_chain_max_minutes');

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * Produits frais encore en route au-delà du seuil : alerte UNE fois par mission au responsable des livraisons,
     * pendant qu'il est encore temps d'agir (appel du livreur, retour à l'atelier). Planifiée toutes les 2 minutes.
     *
     * @return int nombre d'alertes envoyées
     */
    public function alertColdChainBreaches(): int
    {
        $max = $this->coldChainMaxMinutes();
        if ($max === null) {
            return 0;
        }
        $late = DeliveryAssignment::query()->forRealOrders()->with('order')
            ->where('status', AssignmentStatus::PickedUp->value)
            ->where('picked_up_at', '<', now()->subMinutes($max))
            ->whereHas('order.items.product', fn ($p) => $p->where('requires_cold_chain', true))->get();
        $sent = 0;
        foreach ($late as $mission) {
            $sent += $this->alertStaff($mission->order, "assignment:{$mission->id}:cold_chain", 'delivery.cold_chain_exceeded', [User::ROLE_RESP_LIVRAISON]) ? 1 : 0;
        }

        return $sent;
    }

    private function pickUp(DeliveryAssignment $mission, Order $order, User $courier, array $data): void
    {
        if (empty($data['packaging_checked'])) {
            throw DomainException::unprocessable('packaging_check_required', 'Confirmez le contrôle du conditionnement à l\'enlèvement.');
        }
        $mission->update(['status' => AssignmentStatus::PickedUp, 'picked_up_at' => now(), 'packaging_checked' => true]);
        $this->orders->system($order, OrderStatus::PickedUp, $courier, null, ['event' => 'delivery.picked_up', 'assignment_id' => $mission->id]);
    }

    /** Nombre d'essais de code erronés au-delà duquel la saisie est bloquée pour la mission. */
    public const MAX_CODE_ATTEMPTS = 5;

    /**
     * Vérifie le code de remise saisi par le livreur. Un essai erroné est compté et ENREGISTRÉ (transaction propre,
     * validée avant l'erreur renvoyée) ; au 5e, la saisie est bloquée et le responsable des livraisons alerté.
     */
    public function verifyDeliveryCode(DeliveryAssignment $assignment, User $courier, string $code): void
    {
        $result = DB::transaction(function () use ($assignment, $courier, $code) {
            $mission = DeliveryAssignment::query()->with(['order', 'partner'])->lockForUpdate()->findOrFail($assignment->id);
            if ($mission->partner->user_id !== $courier->id) {
                throw DomainException::forbidden('Cette mission ne vous est pas affectée.');
            }
            if ($mission->code_failed_attempts >= self::MAX_CODE_ATTEMPTS) {
                return 'locked';
            }
            $expected = (string) $mission->order->delivery_code;
            if ($expected !== '' && hash_equals($expected, trim($code))) {
                return 'ok';
            }
            $mission->increment('code_failed_attempts');
            $this->journal->record($mission->order, 'delivery', $courier, null, ['event' => 'delivery.code_invalid', 'assignment_id' => $mission->id, 'attempt' => $mission->code_failed_attempts]);
            if ($mission->code_failed_attempts >= self::MAX_CODE_ATTEMPTS) {
                DB::afterCommit(fn () => $this->alertStaff($mission->order, "assignment:{$mission->id}:code_locked", 'delivery.code_locked', [User::ROLE_RESP_LIVRAISON]));

                return 'locked';
            }

            return self::MAX_CODE_ATTEMPTS - $mission->code_failed_attempts;
        });

        if ($result === 'locked') {
            throw DomainException::conflict('delivery_code_locked', 'Trop de codes erronés : saisie bloquée. Remettez la commande « sans code » en notant le nom du destinataire, ou appelez le responsable.');
        }
        if ($result !== 'ok') {
            throw DomainException::unprocessable('delivery_code_invalid', "Code incorrect. Il vous reste {$result} essai(s).", ['remaining_attempts' => $result]);
        }
    }

    /** Notification interne unique (anti-doublon par clé) aux rôles indiqués ; vrai si elle vient d'être envoyée. */
    private function alertStaff(Order $order, string $dedupeKey, string $event, array $roles): bool
    {
        $claimed = NotificationLog::query()->insertOrIgnore([
            'id' => (string) Str::uuid(), 'order_id' => $order->id, 'event' => "staff:{$event}", 'channel' => 'database',
            'dedupe_key' => $dedupeKey, 'delivery_status' => 'sent', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($claimed === 1) {
            Notification::send(User::role($roles)->where('is_active', true)->get(), new StaffOrderActivity($order, $event));
        }

        return $claimed === 1;
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
        $codeEnabled = (bool) $this->settings->get('delivery.code_enabled', false);
        // Le code a été vérifié (et l'essai compté) avant la transaction ; contrôle de cohérence sans recompter.
        if ($codeEnabled && $data['proof_type'] === 'code' && ! hash_equals((string) $order->delivery_code, trim((string) $data['proof_reference']))) {
            throw DomainException::unprocessable('delivery_code_invalid', 'Code de remise incorrect.');
        }
        $mission->update([
            'status' => AssignmentStatus::Delivered,
            'delivered_at' => now(),
            'closed_at' => now(),
            'proof_type' => $data['proof_type'],
            'proof_reference' => mb_substr((string) $data['proof_reference'], 0, 255),
            'cash_collected_xof' => $cash,
        ]);
        $this->recordCashCollected($mission, $order, $courier, (int) $cash);
        // Chaîne du froid : durée hors froid réelle tracée dans l'historique si elle dépasse le seuil paramétré.
        $max = $this->coldChainMaxMinutes();
        $outOfCold = $mission->picked_up_at ? (int) $mission->picked_up_at->diffInMinutes(now()) : null;
        if ($max !== null && $outOfCold !== null && $outOfCold > $max && $order->items()->whereHas('product', fn ($p) => $p->where('requires_cold_chain', true))->exists()) {
            $this->journal->record($order, 'delivery', $courier, "Remis après {$outOfCold} min hors froid (seuil : {$max} min).", [
                'event' => 'delivery.cold_chain_exceeded', 'assignment_id' => $mission->id, 'minutes' => $outOfCold, 'max_minutes' => $max,
            ]);
            DB::afterCommit(fn () => $this->alertStaff($order, "assignment:{$mission->id}:cold_chain", 'delivery.cold_chain_exceeded', [User::ROLE_RESP_LIVRAISON]));
        }
        $this->orders->system($order, OrderStatus::Delivered, $courier, null, ['event' => 'delivery.delivered', 'assignment_id' => $mission->id, 'proof_type' => $data['proof_type']]);
        // Remise sans code : acceptée pour ne jamais bloquer un produit frais, mais vérifiée par le commercial.
        if ($codeEnabled && $data['proof_type'] !== 'code') {
            $this->journal->record($order, 'delivery', $courier, (string) $data['proof_reference'], ['event' => 'delivery.without_code', 'assignment_id' => $mission->id]);
            DB::afterCommit(fn () => $this->alertStaff($order, "assignment:{$mission->id}:without_code", 'delivery.without_code', [User::ROLE_COMMERCIAL]));
        }
    }

    /**
     * Audit M7 : l'espèce encaissée à la livraison devient un paiement enregistré (commande payée → facture,
     * statistiques), plafonné au reste dû. Tout écart avec le montant attendu est tracé pour le règlement.
     * Appelé sous verrou de la commande, avant la transition « livrée ».
     */
    private function recordCashCollected(DeliveryAssignment $mission, Order $order, User $courier, int $cash): void
    {
        $credited = min($cash, max(0, $order->total_xof - $order->paid_xof));
        if ($credited > 0) {
            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'provider' => 'manual:'.GatewayRegistry::CASH_ON_DELIVERY,
                'status' => PaymentStatus::Succeeded,
                'amount_xof' => $credited,
                'currency' => 'XOF',
                'idempotency_key' => 'cod-'.$mission->id,
                'is_manual' => true,
                'recorded_by' => $courier->id,
                'verified_at' => now(),
                'provider_status' => 'Encaissé à la livraison', // la mission est référencée par idempotency_key
            ]);
            $order->paid_xof += $credited;
            $order->payment_status = $order->paid_xof >= $order->total_xof ? OrderPaymentStatus::Paid : OrderPaymentStatus::Pending;
            $this->journal->record($order, 'payment', $courier, null, ['event' => 'payment.cash_collected', 'payment_id' => $payment->id, 'amount_xof' => $credited]);
        }
        if ($mission->cash_to_collect_xof > 0 && $cash !== $mission->cash_to_collect_xof) {
            $this->journal->record($order, 'delivery', $courier, null, ['event' => 'delivery.cash_discrepancy', 'expected_xof' => $mission->cash_to_collect_xof, 'collected_xof' => $cash]);
            Log::warning('delivery.cash_discrepancy', ['order' => $order->reference, 'expected_xof' => $mission->cash_to_collect_xof, 'collected_xof' => $cash]);
        }
    }

    private function fail(DeliveryAssignment $mission, Order $order, User $courier, array $data): void
    {
        $reasons = (array) $this->settings->get('delivery.incident_reasons', []);
        if (! in_array($data['incident_reason'] ?? null, $reasons, true)) {
            throw DomainException::unprocessable('incident_reason_required', 'Choisissez le motif de l\'échec.', ['reasons' => $reasons]);
        }
        // Course échouée : rémunérée à X % si l'échec est dû au client (règle validée le 15/11/2026), sinon non due.
        $clientFault = in_array($data['incident_reason'], (array) $this->settings->get('delivery.client_fault_reasons', []), true);
        // Décision MK Délices (17/11/2026) : les 50 % ne sont dus que si le livreur déclare avoir contacté le client
        // ET attendu sur place le délai paramétré ; sinon la course échouée n'est pas rémunérée.
        $contacted = (bool) ($data['contact_attempted'] ?? false);
        $percent = $clientFault && $contacted ? max(0, min(100, (int) $this->settings->get('delivery.failed_fee_percent', 0))) : 0;
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
