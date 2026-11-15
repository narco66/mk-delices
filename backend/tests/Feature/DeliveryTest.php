<?php

namespace Tests\Feature;

use App\Domain\Shared\Settings;
use App\Models\DeliveryAssignment;
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
        $failWith = function (string $reason) use ($zone, $partner, $manager): DeliveryAssignment {
            $order = $this->readyOrder($zone);
            $id = $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->json('data.id');
            $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept'])->assertOk();
            $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();
            $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'fail', 'incident_reason' => $reason])->assertOk();

            return DeliveryAssignment::query()->findOrFail($id);
        };

        $clientFault = $failWith('client_absent');
        $notClientFault = $failWith('produit_endommage');
        $this->assertSame(650, $clientFault->partner_fee_xof, '50 % de 1 300 FCFA');
        $this->assertSame(0, $notClientFault->partner_fee_xof);
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
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'fail', 'incident_reason' => 'refus_client'])->assertOk();
        $this->assertSame(0, DeliveryAssignment::query()->findOrFail($id)->partner_fee_xof);
    }
}
