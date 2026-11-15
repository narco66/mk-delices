<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/** PREP-006, WA-004 — Scénarios C et D ; T-011, T-018 */
class OrderWorkflowTest extends TestCase
{
    public function test_scenario_d_commercial_accepts_then_preparer_prepares(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $preparer = $this->staff(User::ROLE_PREPARATEUR);

        // Le préparateur ne peut pas accepter une commande (permission orders.manage).
        $this->transition($preparer, $order, 'accept')->assertForbidden();
        $this->transition($commercial, $order, 'accept', ['expected_version' => $order->fresh()->version])->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->actingAs($preparer)->getJson('/api/v1/admin/preparation')->assertOk()->assertJsonPath('data.accepted.0.reference', $order->reference)
            ->assertJsonMissingPath('data.accepted.0.phone');
        $this->transition($preparer, $order, 'start_preparation')->assertOk()->assertJsonPath('data.status', 'preparing');
        $this->transition($preparer, $order, 'mark_ready')->assertStatus(422)->assertJsonPath('error.code', 'packaging_check_required');
        $this->transition($preparer, $order, 'mark_ready', ['packaging_checked' => true])->assertOk()->assertJsonPath('data.status', 'ready');

        $events = OrderEvent::query()->where('order_id', $order->id)->where('type', 'status')->orderBy('occurred_at')->pluck('to_status')->all();
        $this->assertSame(['pending_confirmation', 'accepted', 'preparing', 'ready'], $events);
        $this->assertNotNull(OrderEvent::query()->where('order_id', $order->id)->where('to_status', 'preparing')->value('actor_id'));
        $this->assertTrue($order->fresh()->details_locked, 'Coordonnées figées dès la préparation');
    }

    public function test_mark_ready_without_acceptance_is_refused_and_journal_unchanged(): void
    {
        [$order] = $this->createOrder();
        $before = OrderEvent::query()->count();
        $this->transition($this->staff(User::ROLE_PREPARATEUR), $order, 'mark_ready', ['packaging_checked' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
        $this->assertSame('pending_confirmation', $order->fresh()->status->value);
        $this->assertSame($before, OrderEvent::query()->count());
    }

    public function test_stale_version_is_rejected(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept', ['expected_version' => 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');
    }

    public function test_reject_requires_reason(): void
    {
        [$order] = $this->createOrder();
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'reject')->assertStatus(422)->assertJsonPath('error.code', 'reason_required');
    }

    public function test_details_are_locked_once_preparation_started(): void
    {
        $order = $this->readyOrder();
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->patchJson("/api/v1/admin/orders/{$order->id}/details", ['internal_notes' => 'x'])
            ->assertStatus(409)->assertJsonPath('error.code', 'details_locked');
    }

    public function test_order_journal_is_immutable_at_database_level(): void
    {
        [$order] = $this->createOrder();
        $this->expectException(QueryException::class);
        OrderEvent::query()->where('order_id', $order->id)->update(['reason' => 'falsifié']);
    }

    public function test_scenario_c_whatsapp_draft_is_not_an_order_nor_a_payment_and_is_deduplicated(): void
    {
        $variant = $this->variant(['price_xof' => 2500]);
        $zone = $this->zone();
        $cart = $this->cartWith($variant, 2);
        $headers = $this->cartHeaders(...$cart);

        $draft = $this->postJson('/api/v1/checkout/whatsapp-draft', ['zone_id' => $zone->id], $headers)->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.is_draft', true);
        $url = $draft->json('data.whatsapp_url');
        $this->assertStringStartsWith('https://wa.me/221700000000?text=', $url);
        $decoded = rawurldecode(substr($url, strpos($url, '=') + 1));
        $this->assertStringContainsString($draft->json('data.reference'), $decoded);
        $this->assertStringContainsString('2 × Tiramisu test', $decoded);
        $this->assertStringNotContainsString($draft->json('data.tracking_token'), $decoded, 'Aucun jeton dans le message');

        // Réouverture de WhatsApp : même référence, aucun doublon.
        $again = $this->postJson('/api/v1/checkout/whatsapp-draft', ['zone_id' => $zone->id], $headers)->assertOk();
        $this->assertSame($draft->json('data.reference'), $again->json('data.reference'));
        $this->assertSame(1, Order::query()->count());

        $order = Order::query()->firstOrFail();
        $this->assertSame('unpaid', $order->payment_status->value);
        $this->assertDatabaseCount('payments', 0);

        // Finalisation sur le site depuis le même panier : conversion atomique du brouillon (même référence).
        $slot = $this->firstSlot($cart);
        $placed = $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $slot), $headers + ['Idempotency-Key' => 'wa-convert-0001'])->assertCreated();
        $this->assertSame($draft->json('data.reference'), $placed->json('data.reference'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame('pending_confirmation', $order->fresh()->status->value);
        $this->assertSame('unpaid', $order->fresh()->payment_status->value);
    }

    public function test_commercial_completes_and_confirms_whatsapp_draft(): void
    {
        $zone = $this->zone();
        $cart = $this->cartWith($this->variant(), 1);
        $reference = $this->postJson('/api/v1/checkout/whatsapp-draft', [], $this->cartHeaders(...$cart))->json('data.reference');
        $slot = $this->firstSlot($cart);
        $order = Order::query()->where('reference', $reference)->firstOrFail();
        $commercial = $this->staff(User::ROLE_COMMERCIAL);

        $this->transition($commercial, $order, 'submit')->assertStatus(422)->assertJsonPath('error.code', 'details_incomplete');
        $this->actingAs($commercial)->patchJson("/api/v1/admin/orders/{$order->id}/details", [
            'customer' => ['first_name' => 'Fatou', 'phone' => '781234567'],
            'delivery' => ['zone_id' => $zone->id, 'district' => 'Quartier fictif'],
            'slot_start_at' => $slot,
        ])->assertOk();
        $this->transition($commercial, $order, 'submit')->assertOk()->assertJsonPath('data.status', 'pending_confirmation')
            ->assertJsonPath('data.delivery_fee_xof', 1500);
    }
}
