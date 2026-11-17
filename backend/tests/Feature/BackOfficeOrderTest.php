<?php

namespace Tests\Feature;

use App\Domain\Shared\Settings;
use App\Models\DeliveryAssignment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Commandes saisies au back-office (téléphone, boutique) et correction d'un démarrage de préparation. */
class BackOfficeOrderTest extends TestCase
{
    private function payload(string $zoneId, string $slot, array $overrides = []): array
    {
        return array_replace_recursive([
            'channel' => 'phone',
            'customer' => ['first_name' => 'Mariama', 'last_name' => 'Ndiaye', 'phone' => '77 555 44 33'],
            'delivery' => ['zone_id' => $zoneId, 'district' => 'Quartier fictif', 'landmark' => 'Face à la pharmacie'],
            'slot_start_at' => $slot,
            'internal_notes' => 'Commande prise au téléphone',
        ], $overrides);
    }

    public function test_commercial_enters_phone_order_with_server_side_pricing(): void
    {
        $zone = $this->zone(['delivery_fee_xof' => 1500]);
        $variant = $this->variant(['price_xof' => 2500]);
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->actingAs($commercial);
        $cart = $this->cartWith($variant, 2);
        $slot = $this->firstSlot($cart);
        $key = (string) Str::uuid();

        $response = $this->postJson('/api/v1/admin/orders', $this->payload($zone->id, $slot), $this->cartHeaders(...$cart) + ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.total_xof', 6500);
        $order = Order::query()->findOrFail($response->json('data.id'));
        $this->assertNotEmpty($response->json('data.tracking_token'));

        $this->assertSame('phone', $order->channel);
        $this->assertSame('pending_confirmation', $order->status->value);
        $this->assertNull($order->payment_method, 'Encaissement enregistré ensuite depuis la commande.');
        $this->assertNull($order->terms_accepted_at, 'Le client n\'a pas coché les CGV en ligne.');
        $this->assertSame('Commande prise au téléphone', $order->internal_notes);
        $this->assertNull($order->customer->user_id, 'Le client n\'est jamais rattaché au compte de l\'employé.');
        $this->assertSame('+221775554433', $order->customer->phone);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_id' => $commercial->id, 'to_status' => 'pending_confirmation']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.backoffice_created']);

        // Rejouer la même requête ne crée pas de doublon.
        $this->postJson('/api/v1/admin/orders', $this->payload($zone->id, $slot), $this->cartHeaders(...$cart) + ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.reference', $order->reference);
        $this->assertSame(1, Order::query()->count());

        // Sans paiement en ligne, la commande peut être acceptée puis encaissée manuellement.
        $this->transition($commercial, $order, 'accept')->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    public function test_back_office_order_rules_and_permissions(): void
    {
        $zone = $this->zone(['min_order_xof' => 10000]);
        $variant = $this->variant(['price_xof' => 2500]);

        $this->actingAs($this->staff(User::ROLE_PREPARATEUR));
        $cart = $this->cartWith($variant, 2);
        $slot = $this->firstSlot($cart);
        $this->postJson('/api/v1/admin/orders', $this->payload($zone->id, $slot), $this->cartHeaders(...$cart) + ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        // Mêmes règles que le site : minimum de commande de la zone, canal limité.
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL));
        $this->postJson('/api/v1/admin/orders', $this->payload($zone->id, $slot), $this->cartHeaders(...$cart) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('error.code', 'min_order_not_reached');
        $this->postJson('/api/v1/admin/orders', $this->payload($zone->id, $slot, ['channel' => 'web']), $this->cartHeaders(...$cart) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422);
        $this->assertSame(0, Order::query()->count());
    }

    /** Audit E4 : commande téléphone sans paiement en ligne → le livreur a le montant total à encaisser. */
    /** Commande téléphone non payée, prête et confiée à un livreur ; renvoie [commande, id de mission, livreur]. */
    private function unpaidPhoneOrderOnTheWay(): array
    {
        $zone = $this->zone(['delivery_fee_xof' => 1500]);
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->actingAs($commercial);
        $cart = $this->cartWith($this->variant(['price_xof' => 2500]), 2);
        $id = $this->postJson('/api/v1/admin/orders', $this->payload($zone->id, $this->firstSlot($cart)), $this->cartHeaders(...$cart) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $order = Order::query()->findOrFail($id);
        $this->transition($commercial, $order, 'accept')->assertOk();
        $prep = $this->staff(User::ROLE_PREPARATEUR);
        $this->transition($prep, $order, 'start_preparation')->assertOk();
        $this->transition($prep, $order, 'mark_ready', ['packaging_checked' => true])->assertOk();
        $partner = $this->partner($zone);
        $missionId = $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])
            ->assertCreated()->json('data.id');
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'accept'])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();

        return [$order, $missionId];
    }

    /** Audit M7 : l'espèce encaissée à la livraison est enregistrée comme paiement → commande payée et facturée. */
    public function test_cash_collected_on_delivery_is_recorded_as_payment_and_invoiced(): void
    {
        [$order, $missionId] = $this->unpaidPhoneOrderOnTheWay();
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver', 'proof_type' => 'recipient_confirmation', 'proof_reference' => 'Remis', 'cash_collected_xof' => 6500])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame(6500, $order->paid_xof);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'manual:cash_on_delivery', 'amount_xof' => 6500, 'status' => 'succeeded']);
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count(), 'Commande payée et livrée : facture émise.');
    }

    /** Audit M8 : l'espèce encaissée sur une course gratuite pour le livreur peut quand même être soldée. */
    public function test_cash_held_on_free_course_can_be_settled(): void
    {
        [$order, $missionId] = $this->unpaidPhoneOrderOnTheWay();
        DeliveryAssignment::query()->whereKey($missionId)->update(['partner_fee_xof' => 0]);
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver', 'proof_type' => 'recipient_confirmation', 'proof_reference' => 'Remis', 'cash_collected_xof' => 6500])->assertOk();

        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        $this->actingAs($manager)->getJson('/api/v1/admin/delivery/payouts')->assertOk()
            ->assertJsonPath('data.partners.0.cash_held_xof', 6500)->assertJsonPath('data.partners.0.unsettled_missions', 1);
        $this->postJson('/api/v1/admin/delivery/payouts/settle', ['assignment_ids' => [$missionId]])->assertOk();
        $this->getJson('/api/v1/admin/delivery/payouts')->assertOk()->assertJsonPath('data.partners.0.cash_held_xof', 0);
    }

    /** Audit M7 : un encaissement inférieur au dû est enregistré pour sa valeur et l'écart est tracé. */
    public function test_cash_shortfall_is_traced(): void
    {
        [$order, $missionId] = $this->unpaidPhoneOrderOnTheWay();
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver', 'proof_type' => 'recipient_confirmation', 'proof_reference' => 'Remis', 'cash_collected_xof' => 5000])->assertOk();

        $order->refresh();
        $this->assertSame(5000, $order->paid_xof);
        $this->assertSame('pending', $order->payment_status->value, 'Solde de 1 500 FCFA encore dû.');
        $this->assertTrue($order->events()->get()->contains(fn ($e) => ($e->metadata['event'] ?? null) === 'delivery.cash_discrepancy' && $e->metadata['expected_xof'] === 6500 && $e->metadata['collected_xof'] === 5000));
        $this->assertSame(0, Invoice::query()->where('order_id', $order->id)->count(), 'Pas de facture tant que la commande n\'est pas intégralement payée.');
    }

    public function test_unpaid_phone_order_gives_courier_amount_to_collect(): void
    {
        $zone = $this->zone(['delivery_fee_xof' => 1500]);
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->actingAs($commercial);
        $cart = $this->cartWith($this->variant(['price_xof' => 2500]), 2);
        $id = $this->postJson('/api/v1/admin/orders', $this->payload($zone->id, $this->firstSlot($cart)), $this->cartHeaders(...$cart) + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $order = Order::query()->findOrFail($id);
        $this->transition($commercial, $order, 'accept')->assertOk();
        $prep = $this->staff(User::ROLE_PREPARATEUR);
        $this->transition($prep, $order, 'start_preparation')->assertOk();
        $this->transition($prep, $order, 'mark_ready', ['packaging_checked' => true])->assertOk();

        $partner = $this->partner($zone);
        $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])
            ->assertCreated()->assertJsonPath('data.cash_to_collect_xof', 6500);
    }

    /** Audit E4 : une tentative de paiement en ligne abandonnée ne remplace pas « paiement à la livraison ». */
    public function test_abandoned_online_attempt_keeps_cash_on_delivery(): void
    {
        $settings = app(Settings::class);
        $settings->set('payments.methods', ['wave' => false, 'orange_money' => false, 'card' => false, 'cash_on_delivery' => true]);
        [$order, $token] = $this->createOrder(null, null, ['payment_method' => 'cash_on_delivery']);
        $this->assertSame('cash_on_delivery', $order->payment_method);

        $this->postJson("/api/v1/orders/{$order->reference}/payments", ['method' => 'simulator'], ['X-Tracking-Token' => $token, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $this->assertSame('cash_on_delivery', $order->fresh()->payment_method, 'Le livreur doit toujours encaisser.');
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept')->assertOk();
    }

    public function test_preparation_start_can_be_undone_within_window_with_reason(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept')->assertOk();
        $acceptedAt = $order->fresh()->accepted_at;
        $prep = $this->staff(User::ROLE_PREPARATEUR);
        $this->transition($prep, $order, 'start_preparation')->assertOk();
        $this->assertTrue($order->fresh()->details_locked);

        $this->actingAs($prep)->getJson('/api/v1/admin/preparation')->assertOk()->assertJsonPath('data.preparing.0.undo_until', fn ($v) => $v !== null);
        $this->transition($prep, $order, 'cancel_preparation')->assertStatus(422)->assertJsonPath('error.code', 'reason_required');
        $this->transition($prep, $order, 'cancel_preparation', ['reason' => 'Mauvaise commande démarrée'])->assertOk()->assertJsonPath('data.status', 'accepted');

        $fresh = $order->fresh();
        $this->assertNull($fresh->preparing_at);
        $this->assertFalse($fresh->details_locked, 'La commande redevient modifiable.');
        $this->assertEquals($acceptedAt, $fresh->accepted_at, 'La date d\'acceptation d\'origine est conservée.');
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'from_status' => 'preparing', 'to_status' => 'accepted', 'reason' => 'Mauvaise commande démarrée']);
    }

    public function test_preparation_start_cannot_be_undone_after_window(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept')->assertOk();
        $prep = $this->staff(User::ROLE_PREPARATEUR);
        $this->transition($prep, $order, 'start_preparation')->assertOk();

        $this->travel(16)->minutes();
        $this->actingAs($prep)->getJson('/api/v1/admin/preparation')->assertOk()->assertJsonPath('data.preparing.0.undo_until', null);
        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()->assertJsonMissingPath('meta.available_actions.1');
        $this->transition($prep, $order, 'cancel_preparation', ['reason' => 'Trop tard'])->assertStatus(409)->assertJsonPath('error.code', 'undo_window_expired');
        $this->assertSame('preparing', $order->fresh()->status->value);
    }
}
