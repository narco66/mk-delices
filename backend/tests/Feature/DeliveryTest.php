<?php

namespace Tests\Feature;

use App\Domain\Shared\Settings;
use App\Models\DeliveryAssignment;
use App\Models\NotificationLog;
use App\Models\User;
use Tests\TestCase;

/** DEL-007 — Scénarios E, F, G ; T-012 à T-015 */
class DeliveryTest extends TestCase
{
    public function test_scenario_e_assign_accept_pickup_deliver_with_proof(): void
    {
        $zone = $this->zone(['partner_fee_xof' => 1000]);
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        $courier = $partner->user;

        $missionId = $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])
            ->assertCreated()->assertJsonPath('data.status', 'proposed')->json('data.id');
        $this->assertSame('ready', $order->fresh()->status->value);

        // Avant acceptation : adresse et téléphone masqués.
        $this->actingAs($courier)->getJson("/api/v1/courier/missions/{$missionId}")->assertOk()
            ->assertJsonPath('data.recipient_phone', null)->assertJsonPath('data.landmark', null)->assertJsonPath('data.district', 'Quartier fictif');

        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'accept'])->assertOk()
            ->assertJsonPath('data.recipient_phone', '+221771234567');
        $this->assertSame('assigned', $order->fresh()->status->value);

        // T-014 : livrer avant enlèvement -> 409.
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver', 'proof_type' => 'recipient_confirmation', 'proof_reference' => 'ok'])
            ->assertStatus(409);
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'pick_up'])->assertStatus(422);
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();
        $this->assertSame('picked_up', $order->fresh()->status->value);

        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver'])->assertStatus(422)->assertJsonPath('error.code', 'proof_required');
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver', 'proof_type' => 'recipient_confirmation', 'proof_reference' => 'Remis à Awa'])
            ->assertOk()->assertJsonPath('data.status', 'delivered');

        $mission = DeliveryAssignment::query()->findOrFail($missionId);
        $this->assertNotNull($mission->delivered_at);
        $this->assertSame('recipient_confirmation', $mission->proof_type);
        $this->assertSame('delivered', $order->fresh()->status->value);

        // Après clôture : coordonnées de nouveau masquées pour le livreur.
        $this->getJson("/api/v1/courier/missions/{$missionId}")->assertJsonPath('data.recipient_phone', null);

        // Montant dû au livreur calculé séparément.
        $this->actingAs($manager)->getJson('/api/v1/admin/delivery/payouts')->assertOk()->assertJsonPath('data.partners.0.amount_due_xof', 1000);
    }

    public function test_second_active_assignment_is_rejected(): void
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        $a = $this->partner($zone);
        $b = $this->partner($zone);

        $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $a->id])->assertCreated();
        $this->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $b->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'assignment_exists');
        $this->assertSame(1, DeliveryAssignment::query()->count());
    }

    public function test_partner_outside_zone_or_order_not_ready_cannot_be_assigned(): void
    {
        $zone = $this->zone();
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        [$pending] = $this->createOrder(null, $zone);
        $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $pending->id, 'partner_id' => $this->partner($zone)->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'order_not_ready');

        $order = $this->readyOrder($zone);
        $outsider = $this->partner($this->zone());
        $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $outsider->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'partner_not_eligible');
    }

    public function test_scenario_g_courier_cannot_access_mission_of_another_courier(): void
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $a = $this->partner($zone);
        $b = $this->partner($zone);
        $missionId = $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))
            ->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $a->id])->json('data.id');

        $this->actingAs($b->user)->getJson("/api/v1/courier/missions/{$missionId}")->assertForbidden();
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'accept'])->assertForbidden();
        $this->getJson('/api/v1/courier/missions')->assertOk()->assertJsonCount(0, 'data.active');
        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertForbidden();
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->getJson('/api/v1/admin/stats?from=2026-01-01&to=2026-12-31')->assertForbidden();
        $this->assertSame('proposed', DeliveryAssignment::query()->find($missionId)->status->value);
    }

    public function test_scenario_f_failed_delivery_then_reassignment(): void
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        $missionId = $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->json('data.id');
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'accept'])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();

        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'fail'])->assertStatus(422);
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", [
            'action' => 'fail', 'incident_reason' => 'client_absent', 'incident_note' => 'Personne au portail', 'product_condition' => 'intact', 'contact_attempted' => true,
        ])->assertOk()->assertJsonPath('data.status', 'failed');

        $mission = DeliveryAssignment::query()->findOrFail($missionId);
        $this->assertNotNull($mission->failed_at);
        $this->assertSame('client_absent', $mission->incident_reason);
        $this->assertSame('delivery_failed', $order->fresh()->status->value);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'to_status' => 'delivery_failed']);

        // Le responsable examine le dossier et réaffecte (transition autorisée delivery_failed -> assigned).
        $this->actingAs($manager)->getJson('/api/v1/admin/delivery/board')->assertOk();
        $newMission = $this->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->assertCreated()->json('data.id');
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$newMission}/actions", ['action' => 'accept'])->assertOk();
        $this->assertSame('assigned', $order->fresh()->status->value);

        // Audit M6 : un SECOND échec doit de nouveau alerter l'équipe (et non être pris pour un doublon).
        $this->postJson("/api/v1/courier/missions/{$newMission}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$newMission}/actions", ['action' => 'fail', 'incident_reason' => 'client_injoignable'])->assertOk();
        $this->assertSame(2, NotificationLog::query()->where('order_id', $order->id)->where('event', 'staff:delivery_failed')->count());
        $this->assertSame(2, $manager->fresh()->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === 'order.delivery_failed')->count());
    }

    public function test_courier_refusal_frees_order_for_reassignment(): void
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        $missionId = $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->json('data.id');
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'refuse', 'reason' => 'Panne de moto'])->assertOk();
        $this->assertSame('ready', $order->fresh()->status->value);
        $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $this->partner($zone)->id])->assertCreated();
    }

    public function test_failed_course_is_paid_50_percent_only_when_the_client_is_at_fault(): void
    {
        $zone = $this->zone(['partner_fee_xof' => 1300]);
        $partner = $this->partner($zone);
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        // Commandes prêtes créées d'abord (par des clients invités), avant toute connexion du livreur.
        $orders = [$this->readyOrder($zone), $this->readyOrder($zone), $this->readyOrder($zone)];
        $failWith = function (string $reason, bool $contacted = true) use (&$orders, $partner, $manager): DeliveryAssignment {
            $order = array_shift($orders);
            $id = $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->json('data.id');
            $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept'])->assertOk();
            $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();
            $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'fail', 'incident_reason' => $reason, 'contact_attempted' => $contacted])->assertOk();

            return DeliveryAssignment::query()->findOrFail($id);
        };

        $clientFault = $failWith('client_absent');
        $notClientFault = $failWith('produit_endommage');
        $this->assertSame(650, $clientFault->partner_fee_xof, '50 % de 1 300 FCFA');
        $this->assertSame(0, $notClientFault->partner_fee_xof);
        // Décision du 17/11/2026 : sans tentative de contact ni attente déclarées, pas de rémunération.
        $noContact = $failWith('client_absent', false);
        $this->assertSame(0, $noContact->partner_fee_xof);
        $this->assertDatabaseHas('order_events', ['order_id' => $noContact->order_id, 'to_status' => 'delivery_failed']);
        $this->assertDatabaseHas('order_events', ['order_id' => $clientFault->order_id, 'to_status' => 'delivery_failed']);

        // Montants dus : côté responsable et côté livreur.
        $this->actingAs($manager)->getJson('/api/v1/admin/delivery/payouts')->assertOk()
            ->assertJsonPath('data.partners.0.amount_due_xof', 650)
            ->assertJsonPath('data.partners.0.unsettled_missions', 1)
            ->assertJsonCount(1, 'data.unsettled');
        $this->actingAs($partner->user)->getJson('/api/v1/courier/missions')->assertJsonPath('data.amount_due_xof', 650);

        // Règlement : seule la course due est réglée.
        $this->actingAs($manager)->postJson('/api/v1/admin/delivery/payouts/settle', ['assignment_ids' => [$clientFault->id, $notClientFault->id]])
            ->assertOk()->assertJsonPath('data.settled', 1);
        $this->assertNotNull($clientFault->fresh()->settled_at);
        $this->assertNull($notClientFault->fresh()->settled_at);
    }

    public function test_failed_course_percentage_is_configurable(): void
    {
        app(Settings::class)->set('delivery.failed_fee_percent', 0);
        $zone = $this->zone(['partner_fee_xof' => 1000]);
        $partner = $this->partner($zone);
        $order = $this->readyOrder($zone);
        $id = $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->json('data.id');
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept']);
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'pick_up', 'packaging_checked' => true]);
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'fail', 'incident_reason' => 'refus_client', 'contact_attempted' => true])->assertOk();
        $this->assertSame(0, DeliveryAssignment::query()->findOrFail($id)->partner_fee_xof);
    }

    /** Commande prête + livreur de sa zone + mission proposée ; renvoie [commande, livreur, id de mission, responsable]. */
    private function proposedMission(): array
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        $id = $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->assertCreated()->json('data.id');

        return [$order, $partner, $id, $manager];
    }

    /** Audit espace livreur E2 : un livreur « indisponible » ne reçoit pas de mission. */
    public function test_unavailable_courier_cannot_be_assigned(): void
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        $this->actingAs($partner->user)->putJson('/api/v1/courier/availability', ['available' => false])->assertOk();

        $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'partner_unavailable');
        $this->assertSame(0, DeliveryAssignment::query()->count());
    }

    /** Audit espace livreur M1 : le livreur rend une mission acceptée avant l'enlèvement ; le responsable est prévenu. */
    public function test_courier_can_release_accepted_mission_before_pickup(): void
    {
        [$order, $partner, $id, $manager] = $this->proposedMission();
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept'])->assertOk()
            ->assertJsonPath('data.available_actions', ['pick_up', 'release']);
        $readyAlertsBefore = NotificationLog::query()->where('order_id', $order->id)->where('event', 'staff:ready')->count();

        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'release'])->assertStatus(422)->assertJsonPath('error.code', 'reason_required');
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'release', 'reason' => 'Crevaison'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.recipient_phone', null);

        $this->assertSame('ready', $order->fresh()->status->value);
        $this->assertSame($readyAlertsBefore + 1, NotificationLog::query()->where('order_id', $order->id)->where('event', 'staff:ready')->count(), 'Le responsable est prévenu que la commande est à réaffecter.');
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'reason' => 'Crevaison']);
        // Réaffectation possible immédiatement.
        $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $this->partner(DeliveryAssignment::query()->findOrFail($id)->order->zone)->id])->assertCreated();
    }

    public function test_mission_cannot_be_released_after_pickup(): void
    {
        [, $partner, $id] = $this->proposedMission();
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept'])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'release', 'reason' => 'Trop tard'])->assertStatus(409);
    }

    /** Audit espace livreur E1 : mission non acceptée à temps → une seule alerte au responsable. */
    public function test_unaccepted_mission_alerts_delivery_manager_once(): void
    {
        app(Settings::class)->set('delivery.accept_timeout_minutes', 10);
        [, , , $manager] = $this->proposedMission();
        $this->artisan('mk:alert-unaccepted-missions')->expectsOutputToContain('0 alerte(s)')->assertSuccessful();

        $this->travel(11)->minutes();
        $this->artisan('mk:alert-unaccepted-missions')->expectsOutputToContain('1 alerte(s)')->assertSuccessful();
        $this->artisan('mk:alert-unaccepted-missions')->expectsOutputToContain('0 alerte(s)')->assertSuccessful();
        $this->assertSame(1, $manager->fresh()->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === 'delivery.not_accepted')->count());
    }

    /** Audit espace livreur M2 : le livreur voit les espèces encaissées qu'il doit remettre. */
    public function test_courier_overview_shows_cash_to_hand_over(): void
    {
        [, $partner, $id] = $this->proposedMission();
        DeliveryAssignment::query()->whereKey($id)->update(['status' => 'delivered', 'delivered_at' => now(), 'closed_at' => now(), 'cash_collected_xof' => 6500]);
        $this->actingAs($partner->user)->getJson('/api/v1/courier/missions')->assertOk()->assertJsonPath('data.cash_to_hand_over_xof', 6500);
        DeliveryAssignment::query()->whereKey($id)->update(['settled_at' => now()]);
        $this->getJson('/api/v1/courier/missions')->assertOk()->assertJsonPath('data.cash_to_hand_over_xof', 0);
    }

    /** Mission acceptée et enlevée ; renvoie [commande, livreur, id, responsable]. */
    private function pickedUpMission(): array
    {
        [$order, $partner, $id, $manager] = $this->proposedMission();
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept'])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();

        return [$order->fresh(), $partner, $id, $manager];
    }

    /** Code de remise : connu du client et du commercial, jamais du livreur ; le bon code valide la livraison. */
    public function test_delivery_code_is_hidden_from_courier_and_validates_delivery(): void
    {
        [$order, $partner, $id] = $this->pickedUpMission();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $order->delivery_code);

        $mission = $this->actingAs($partner->user)->getJson("/api/v1/courier/missions/{$id}")->assertOk()->json('data');
        $this->assertStringNotContainsString($order->delivery_code, json_encode($mission), 'Le livreur ne doit jamais voir le code.');
        $this->assertSame(5, $mission['code_attempts_left']);
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.delivery_code', $order->delivery_code);
        $this->actingAs($this->staff(User::ROLE_PREPARATEUR))->getJson("/api/v1/admin/orders/{$order->id}")->assertNotFound();

        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'deliver', 'proof_type' => 'code', 'proof_reference' => $order->delivery_code])
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertSame(0, NotificationLog::query()->where('order_id', $order->id)->where('event', 'staff:delivery.without_code')->count());
    }

    /** Codes erronés comptés et conservés ; au 5e, saisie bloquée et responsable alerté ; la remise sans code reste possible. */
    public function test_wrong_codes_are_counted_then_locked_and_fallback_is_flagged(): void
    {
        [$order, $partner, $id, $manager] = $this->pickedUpMission();
        $wrong = $order->delivery_code === '0000' ? '1111' : '0000';
        for ($i = 4; $i >= 1; $i--) {
            $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'deliver', 'proof_type' => 'code', 'proof_reference' => $wrong])
                ->assertStatus(422)->assertJsonPath('error.code', 'delivery_code_invalid')->assertJsonPath('error.details.remaining_attempts', $i);
        }
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'deliver', 'proof_type' => 'code', 'proof_reference' => $wrong])
            ->assertStatus(409)->assertJsonPath('error.code', 'delivery_code_locked');
        // Même le bon code est refusé une fois bloqué (pas d'essais au hasard).
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'deliver', 'proof_type' => 'code', 'proof_reference' => $order->delivery_code])
            ->assertStatus(409)->assertJsonPath('error.code', 'delivery_code_locked');
        $this->assertSame(5, DeliveryAssignment::query()->findOrFail($id)->code_failed_attempts);
        $this->assertSame(1, $manager->fresh()->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === 'delivery.code_locked')->count());

        // Remise sans code : acceptée, tracée et signalée au commercial pour vérification.
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'deliver', 'proof_type' => 'recipient_confirmation', 'proof_reference' => 'Remis à Moussa, frère de la cliente'])
            ->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertTrue($order->events()->get()->contains(fn ($e) => ($e->metadata['event'] ?? null) === 'delivery.without_code'));
        $this->assertSame(1, $commercial->fresh()->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === 'delivery.without_code')->count());
    }

    /** Le client voit son code sur le suivi tant que la commande n'est pas remise. */
    public function test_customer_sees_delivery_code_until_delivered(): void
    {
        [$order, $token] = $this->createOrder();
        $code = $order->fresh()->delivery_code;
        $this->getJson("/api/v1/orders/{$order->reference}/status", ['X-Tracking-Token' => $token])->assertOk()->assertJsonPath('data.delivery_code', $code);
        $order->update(['status' => 'delivered']);
        $this->getJson("/api/v1/orders/{$order->reference}/status", ['X-Tracking-Token' => $token])->assertOk()->assertJsonPath('data.delivery_code', null);
    }
}
