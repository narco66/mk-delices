<?php

namespace Tests;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Shared\Settings;
use App\Models\Category;
use App\Models\DeliveryPartner;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Requêtes émises comme le SPA first-party (Sanctum stateful : session + cookies).
        $this->withHeader('Referer', 'http://localhost:5173/');
        $this->seed([RolesAndPermissionsSeeder::class, SettingsSeeder::class]);
        // Horaires ouverts tous les jours pour des tests indépendants du jour d'exécution.
        $all = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['08:00', '20:00']]);
        app(Settings::class)->set('business.hours', $all);
        app(Settings::class)->set('business.whatsapp_number', '221700000000');
    }

    protected function staff(string $role): User
    {
        $user = User::query()->create(['name' => ucfirst($role).' Test', 'email' => $role.'-'.Str::lower(Str::random(6)).'@test.local', 'password' => 'Password-123', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    protected function zone(array $attributes = []): DeliveryZone
    {
        return DeliveryZone::query()->create($attributes + [
            'name' => 'Zone test '.Str::random(4), 'slug' => 'zone-'.Str::random(8), 'active' => true,
            'delivery_fee_xof' => 1500, 'partner_fee_xof' => 1000, 'min_order_xof' => null,
        ]);
    }

    protected function variant(array $variant = [], array $product = []): ProductVariant
    {
        $category = Category::query()->firstOrCreate(['slug' => 'tiramisus'], ['name' => 'Tiramisus', 'active' => true]);
        $p = Product::query()->create($product + [
            'category_id' => $category->id, 'slug' => 'tiramisu-'.Str::random(8), 'name' => 'Tiramisu test',
            'allergens' => ['lait', 'œufs', 'gluten'], 'ingredients' => 'Mascarpone, œufs', 'status' => ProductStatus::Published,
            'storage_instructions' => 'Au frais', 'requires_cold_chain' => true,
        ]);

        return $p->variants()->create($variant + ['sku' => 'SKU-'.Str::upper(Str::random(8)), 'label' => 'Individuel', 'price_xof' => 2500, 'available' => true, 'prep_minutes' => 60]);
    }

    protected function partner(DeliveryZone $zone): DeliveryPartner
    {
        $user = $this->staff(User::ROLE_LIVREUR);
        $partner = DeliveryPartner::query()->create(['user_id' => $user->id, 'display_name' => $user->name, 'phone' => '+221770000099', 'active' => true]);
        $partner->zones()->attach($zone->id);

        return $partner;
    }

    /** @return array{0:string,1:string} [cart_id, cart_token] */
    protected function cartWith(ProductVariant $variant, int $qty = 2): array
    {
        $cart = $this->postJson('/api/v1/cart')->assertCreated()->json('data');
        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => $qty], $this->cartHeaders($cart['cart_id'], $cart['cart_token']))->assertOk();

        return [$cart['cart_id'], $cart['cart_token']];
    }

    protected function cartHeaders(string $id, string $token): array
    {
        return ['X-Cart-Id' => $id, 'X-Cart-Token' => $token];
    }

    protected function firstSlot(array $cart): string
    {
        return $this->postJson('/api/v1/checkout/quote', [], $this->cartHeaders(...$cart))->json('data.slots.0.start_at');
    }

    protected function orderPayload(DeliveryZone $zone, string $slot, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer' => ['first_name' => 'Awa', 'phone' => '+221 77 123 45 67'],
            'delivery' => ['zone_id' => $zone->id, 'district' => 'Quartier fictif', 'landmark' => 'Près de la place'],
            'slot_start_at' => $slot,
            'payment_method' => 'simulator',
            'accepted_terms' => true,
        ], $overrides);
    }

    protected function placeOrder(ProductVariant $variant, DeliveryZone $zone, int $qty = 2, array $overrides = [], ?string $key = null): TestResponse
    {
        $cart = $this->cartWith($variant, $qty);

        return $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $this->firstSlot($cart), $overrides),
            $this->cartHeaders(...$cart) + ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** Crée une commande et renvoie [Order, tracking_token]. */
    protected function createOrder(?ProductVariant $variant = null, ?DeliveryZone $zone = null, array $overrides = []): array
    {
        $response = $this->placeOrder($variant ?? $this->variant(), $zone ?? $this->zone(), 2, $overrides)->assertCreated();

        return [Order::query()->where('reference', $response->json('data.reference'))->firstOrFail(), $response->json('data.tracking_token')];
    }

    /** Simule le paiement complet : session PSP simulée puis webhook signé « réussi ». */
    protected function payOrder(Order $order, string $token): void
    {
        $session = $this->postJson("/api/v1/orders/{$order->reference}/payments", ['method' => 'simulator'], ['X-Tracking-Token' => $token, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $reference = $order->payments()->firstOrFail()->provider_reference;
        $this->postJson("/api/v1/dev/payment-simulator/{$reference}/complete", ['outcome' => 'success'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status->value, 'Paiement non confirmé : '.$session->getContent());
    }

    protected function transition(User $actor, Order $order, string $action, array $extra = []): TestResponse
    {
        return $this->actingAs($actor)->postJson("/api/v1/admin/orders/{$order->id}/transitions", ['action' => $action] + $extra);
    }

    /** Amène une commande payée jusqu'à l'état « ready ». */
    protected function readyOrder(?DeliveryZone $zone = null): Order
    {
        $zone ??= $this->zone();
        [$order, $token] = $this->createOrder(null, $zone);
        $this->payOrder($order, $token);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept')->assertOk();
        $prep = $this->staff(User::ROLE_PREPARATEUR);
        $this->transition($prep, $order, 'start_preparation')->assertOk();
        $this->transition($prep, $order, 'mark_ready', ['packaging_checked' => true])->assertOk();

        return $order->fresh();
    }
}
