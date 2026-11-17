<?php

namespace Tests\Feature;

use App\Domain\Shared\Settings;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CART-002, ORDER-003 — T-002, T-003, T-004, T-005, T-016, T-017, T-023 */
class CheckoutTest extends TestCase
{
    public function test_client_side_price_is_ignored_and_total_recomputed_by_server(): void
    {
        $variant = $this->variant(['price_xof' => 2500]);
        $zone = $this->zone(['delivery_fee_xof' => 1500]);
        $cart = $this->postJson('/api/v1/cart')->json('data');
        $headers = $this->cartHeaders($cart['cart_id'], $cart['cart_token']);

        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 3, 'price_xof' => 1, 'unit_price_xof' => 1], $headers)
            ->assertOk()->assertJsonPath('data.subtotal_xof', 7500);

        $slot = $this->postJson('/api/v1/checkout/quote', ['zone_id' => $zone->id], $headers)
            ->assertOk()->assertJsonPath('data.total_xof', 9000)->json('data.slots.0.start_at');

        $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $slot, ['total_xof' => 10, 'subtotal_xof' => 10]), $headers + ['Idempotency-Key' => 'price-tamper-01'])
            ->assertCreated()->assertJsonPath('data.total_xof', 9000)->assertJsonPath('data.subtotal_xof', 7500);
    }

    public function test_guest_order_is_created_with_unpredictable_reference_and_private_tracking(): void
    {
        $response = $this->placeOrder($this->variant(), $this->zone())->assertCreated();
        $reference = $response->json('data.reference');
        $token = $response->json('data.tracking_token');

        $this->assertMatchesRegularExpression('/^MKD-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $reference);
        $response->assertJsonPath('data.status', 'pending_confirmation')->assertJsonPath('data.payment_status', 'unpaid');

        $order = Order::query()->where('reference', $reference)->first();
        $this->assertNotSame($token, $order->tracking_token_hash, 'Le jeton ne doit jamais être stocké en clair');
        $this->assertSame('+221771234567', $order->customer_snapshot['phone']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'to_status' => 'pending_confirmation']);

        $this->getJson("/api/v1/orders/{$reference}/status", ['X-Tracking-Token' => $token])->assertOk()->assertJsonPath('data.reference', $reference);
        // T-017 : sans jeton ou avec un mauvais jeton -> 404 indistinct.
        $this->getJson("/api/v1/orders/{$reference}/status")->assertNotFound();
        $this->getJson("/api/v1/orders/{$reference}/status", ['X-Tracking-Token' => 'mauvais'])->assertNotFound();
        $this->getJson('/api/v1/orders/MKD-ZZZZ-ZZZZ/status', ['X-Tracking-Token' => $token])->assertNotFound();
    }

    public function test_double_click_returns_same_order_and_reused_key_with_other_payload_is_rejected(): void
    {
        $variant = $this->variant();
        $zone = $this->zone();
        $cart = $this->cartWith($variant);
        $payload = $this->orderPayload($zone, $this->firstSlot($cart));
        $headers = $this->cartHeaders(...$cart) + ['Idempotency-Key' => 'double-click-0001'];

        $first = $this->postJson('/api/v1/checkout/orders', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/v1/checkout/orders', $payload, $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertSame(1, Order::query()->count());
        // Audit M1 : le rejeu rend le même jeton de suivi, mais il n'est jamais lisible en base.
        $token = $first->json('data.tracking_token');
        $this->assertSame($token, $second->json('data.tracking_token'));
        $stored = (string) DB::table('idempotency_keys')->where('key', 'double-click-0001')->value('response_json');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($token, $stored);
        $this->assertStringNotContainsString($first->json('data.reference'), $stored);

        $payload['customer']['first_name'] = 'Autre';
        $this->postJson('/api/v1/checkout/orders', $payload, $headers)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
        // Nouvelle clé sur le même panier : pas de seconde commande.
        $this->postJson('/api/v1/checkout/orders', $payload, $this->cartHeaders(...$cart) + ['Idempotency-Key' => 'double-click-0002'])
            ->assertStatus(409)->assertJsonPath('error.code', 'cart_converted');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $cart = $this->cartWith($this->variant());
        $this->postJson('/api/v1/checkout/orders', $this->orderPayload($this->zone(), $this->firstSlot($cart)), $this->cartHeaders(...$cart))
            ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');
    }

    public function test_inactive_zone_blocks_checkout_with_explanation(): void
    {
        $zone = $this->zone(['active' => false]);
        $cart = $this->cartWith($this->variant());
        $this->postJson('/api/v1/checkout/quote', ['zone_id' => $zone->id], $this->cartHeaders(...$cart))
            ->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.issues.0.code', 'zone_unavailable');
        $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $this->firstSlot($cart)), $this->cartHeaders(...$cart) + ['Idempotency-Key' => 'zone-off-0001'])
            ->assertStatus(422)->assertJsonPath('error.code', 'zone_unavailable');
    }

    public function test_validation_errors_are_attached_to_fields(): void
    {
        $cart = $this->cartWith($this->variant());
        $this->postJson('/api/v1/checkout/orders', ['accepted_terms' => false], $this->cartHeaders(...$cart) + ['Idempotency-Key' => 'invalid-0001'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['customer.phone', 'customer.first_name', 'slot_start_at', 'accepted_terms']], 'request_id']);
    }

    public function test_invalid_senegalese_phone_is_rejected(): void
    {
        $this->placeOrder($this->variant(), $this->zone(), 1, ['customer' => ['phone' => '+33 6 12 34 56 78']])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_phone');
    }

    public function test_full_slot_is_refused_and_next_slot_proposed(): void
    {
        app(Settings::class)->set('ordering.slot_capacity', 1);
        $zone = $this->zone();
        $variant = $this->variant();
        $cartA = $this->cartWith($variant, 1);
        $slot = $this->firstSlot($cartA);
        $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $slot), $this->cartHeaders(...$cartA) + ['Idempotency-Key' => 'slot-a-0001'])->assertCreated();

        $cartB = $this->cartWith($variant, 1);
        $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $slot), $this->cartHeaders(...$cartB) + ['Idempotency-Key' => 'slot-b-0001'])
            ->assertStatus(409)->assertJsonPath('error.code', 'slot_unavailable')->assertJsonStructure(['error' => ['details' => ['next_slot' => ['start_at']]]]);
    }

    public function test_last_unit_can_only_be_reserved_once(): void
    {
        $variant = $this->variant(['stock_qty' => 1]);
        $zone = $this->zone();
        $cartA = $this->cartWith($variant, 1);
        $cartB = $this->cartWith($variant, 1);

        $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $this->firstSlot($cartA)), $this->cartHeaders(...$cartA) + ['Idempotency-Key' => 'stock-a-0001'])->assertCreated();
        $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $this->firstSlot($cartA)), $this->cartHeaders(...$cartB) + ['Idempotency-Key' => 'stock-b-0001'])
            ->assertStatus(409);
        $this->assertSame(0, $variant->fresh()->stock_qty);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_catalog_price_change_does_not_alter_existing_order_snapshot(): void
    {
        $variant = $this->variant(['price_xof' => 2500]);
        [$order] = $this->createOrder($variant);
        $variant->update(['price_xof' => 9999]);

        $item = $order->items()->first();
        $this->assertSame(2500, $item->unit_price_xof);
        $this->assertSame(5000, $order->fresh()->subtotal_xof);
    }

    public function test_promotion_discount_is_capped_and_not_cumulative(): void
    {
        Promotion::query()->create(['code' => 'DIX', 'name' => '10%', 'discount_type' => 'percent', 'discount_value' => 10, 'max_discount_xof' => 300, 'active' => true]);
        $variant = $this->variant(['price_xof' => 5000]);
        $cart = $this->cartWith($variant, 1);
        $this->putJson('/api/v1/cart/promotion', ['code' => 'dix'], $this->cartHeaders(...$cart))->assertOk()->assertJsonPath('data.discount_xof', 300);
        $this->putJson('/api/v1/cart/promotion', ['code' => 'INCONNU'], $this->cartHeaders(...$cart))->assertOk()
            ->assertJsonPath('data.discount_xof', 0)->assertJsonPath('data.issues.0.code', 'promotion_invalid');
    }

    public function test_customer_can_cancel_only_before_acceptance(): void
    {
        [$order, $token] = $this->createOrder();
        $this->postJson("/api/v1/orders/{$order->reference}/cancel", ['reason' => 'Erreur de date'], ['X-Tracking-Token' => $token])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        [$order2, $token2] = $this->createOrder();
        $this->payOrder($order2, $token2);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order2, 'accept')->assertOk();
        $this->postJson("/api/v1/orders/{$order2->reference}/cancel", ['reason' => 'Trop tard'], ['X-Tracking-Token' => $token2])
            ->assertStatus(409)->assertJsonPath('error.code', 'cancellation_not_allowed');
    }
}
