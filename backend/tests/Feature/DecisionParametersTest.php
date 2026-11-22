<?php

namespace Tests\Feature;

use App\Domain\Deliveries\DeliveryService;
use App\Domain\Shared\Settings;
use App\Models\User;
use Tests\TestCase;

/**
 * Décisions de MK Délices gérées par des paramètres du back-office (20/11/2026) :
 * D-QUA-01 seuil de la chaîne du froid, D-PAY-04 plafond du paiement à la livraison.
 */
class DecisionParametersTest extends TestCase
{
    /** Commande prête, mission acceptée et produit frais enlevé ; renvoie [commande, id de mission, livreur, responsable]. */
    private function pickedUpFreshOrder(): array
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        $missionId = $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->json('data.id');
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'accept'])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();

        return [$order, $missionId, $partner->user, $manager];
    }

    public function test_cold_chain_threshold_alerts_once_and_is_traced_at_delivery(): void
    {
        [$order, $missionId, $courier, $manager] = $this->pickedUpFreshOrder();
        $deliveries = app(DeliveryService::class);

        // Seuil non fixé : aucun contrôle, aucune heure limite.
        $this->travel(90)->minutes();
        $this->assertSame(0, $deliveries->alertColdChainBreaches());
        $this->actingAs($courier)->getJson("/api/v1/courier/missions/{$missionId}")->assertJsonPath('data.cold_chain_deadline_at', null);

        app(Settings::class)->set('quality.cold_chain_max_minutes', 30);
        $this->assertNotNull($this->getJson("/api/v1/courier/missions/{$missionId}")->json('data.cold_chain_deadline_at'));
        $this->assertSame(1, $deliveries->alertColdChainBreaches(), 'produit frais en route depuis 90 min');
        $this->assertSame(0, $deliveries->alertColdChainBreaches(), 'une seule alerte par mission');
        $this->assertSame('delivery.cold_chain_exceeded', $manager->notifications()->first()->data['event']);

        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver', 'proof_type' => 'code', 'proof_reference' => $order->fresh()->delivery_code])->assertOk();
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'reason' => 'Remis après 90 min hors froid (seuil : 30 min).']);
        $this->assertSame(1, $manager->notifications()->count(), 'pas de seconde alerte à la remise');
    }

    public function test_delivery_within_cold_chain_threshold_raises_nothing(): void
    {
        app(Settings::class)->set('quality.cold_chain_max_minutes', 30);
        [$order, $missionId, $courier, $manager] = $this->pickedUpFreshOrder();
        $this->travel(20)->minutes();

        $this->assertSame(0, app(DeliveryService::class)->alertColdChainBreaches());
        $this->actingAs($courier)->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver', 'proof_type' => 'code', 'proof_reference' => $order->fresh()->delivery_code])->assertOk();
        $this->assertSame(0, $manager->notifications()->count());
    }

    public function test_cash_on_delivery_cap_applies_to_online_orders(): void
    {
        $settings = app(Settings::class);
        $settings->set('payments.methods', ['wave' => false, 'orange_money' => false, 'card' => false, 'cash_on_delivery' => true]);
        $zone = $this->zone(['delivery_fee_xof' => 1500]);
        $variant = $this->variant();

        // Total 6 500 FCFA : accepté sans plafond.
        $this->placeOrder($variant, $zone, 2, ['payment_method' => 'cash_on_delivery'])->assertCreated();

        $settings->set('payments.cash_on_delivery_max_xof', 5000);
        $this->placeOrder($variant, $zone, 2, ['payment_method' => 'cash_on_delivery'])->assertStatus(422)
            ->assertJsonPath('error.code', 'cash_on_delivery_limit')->assertJsonPath('error.details.max_xof', 5000)
            ->assertJsonPath('error.message', 'Le paiement à la livraison est limité à 5 000 FCFA. Choisissez un paiement en ligne pour cette commande.');

        $settings->set('payments.cash_on_delivery_max_xof', 10000);
        $this->placeOrder($variant, $zone, 2, ['payment_method' => 'cash_on_delivery'])->assertCreated();
    }

    public function test_cash_on_delivery_cap_is_validated_in_settings(): void
    {
        $this->actingAs($this->staff(User::ROLE_ADMIN))
            ->putJson('/api/v1/admin/settings', ['values' => ['payments.cash_on_delivery_max_xof' => -5]])->assertStatus(422);
        $this->putJson('/api/v1/admin/settings', ['values' => ['payments.cash_on_delivery_max_xof' => 25000]])->assertOk();
        $this->assertSame(25000, app(Settings::class)->get('payments.cash_on_delivery_max_xof'));
    }

    /** Audit du 22/11/2026 (W-04) : tout ou rien, erreur rattachée au paramètre, taux de TVA décimal accepté. */
    public function test_settings_are_saved_all_or_nothing_with_the_faulty_key_named(): void
    {
        $this->actingAs($this->staff(User::ROLE_ADMIN))->putJson('/api/v1/admin/settings', ['values' => [
            'invoicing.vat_rate_percent' => 5.5,
            'business.instagram_url' => 'http://instagram.com/mkdelices',
        ]])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['values.business.instagram_url']]]);
        $this->assertSame(18, app(Settings::class)->get('invoicing.vat_rate_percent'), 'Rien n\'est enregistré si une valeur est refusée.');

        $this->putJson('/api/v1/admin/settings', ['values' => ['invoicing.vat_rate_percent' => 5.5]])->assertOk();
        $this->assertSame(5.5, app(Settings::class)->get('invoicing.vat_rate_percent'));
    }
}
