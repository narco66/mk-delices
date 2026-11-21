<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * API de l'application mobile : authentification par jeton « Bearer » (sans cookie ni CSRF),
 * mêmes droits et mêmes règles que le site web.
 */
class MobileApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Une application native n'envoie ni Referer ni Origin : requêtes non « stateful », sans session.
        $this->withoutHeader('Referer');
    }

    private function token(string $email, string $password = 'Password-123', string $device = 'Pixel 8 de test'): string
    {
        return $this->postJson('/api/v1/auth/token', ['email' => $email, 'password' => $password, 'device_name' => $device])
            ->assertCreated()->assertJsonPath('data.token_type', 'Bearer')->json('data.token');
    }

    private function api(string $method, string $uri, string $token, array $data = [], array $headers = []): TestResponse
    {
        // Chaque appel repart de zéro, comme une vraie application : aucune session ni cookie conservé.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $data, $headers + ['Authorization' => "Bearer {$token}"]);
    }

    private function client(): User
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Aminata', 'last_name' => 'Fall', 'email' => 'aminata@example.test', 'phone' => '77 111 22 33',
            'password' => 'MotDePasse2026', 'password_confirmation' => 'MotDePasse2026', 'accepted_privacy' => true,
        ])->assertCreated();

        return User::query()->where('email', 'aminata@example.test')->firstOrFail();
    }

    /** 21/11/2026 : l'application mobile reçoit des adresses d'image joignables depuis le téléphone. */
    public function test_product_image_urls_use_the_host_the_client_called(): void
    {
        $product = $this->variant()->product;
        ProductImage::query()->create([
            'product_id' => $product->id, 'path' => "products/{$product->id}/photo-lg.webp", 'alt' => 'Photo',
            'variants' => ['sm' => "products/{$product->id}/photo-sm.webp", 'lg' => "products/{$product->id}/photo-lg.webp"],
            'width' => 1600, 'height' => 1600, 'sort_order' => 1, 'is_illustrative' => true,
        ]);

        // Émulateur Android (10.0.2.2) ou téléphone sur le réseau local : jamais « localhost ».
        $image = $this->getJson("http://10.0.2.2:8000/api/v1/catalog/products/{$product->slug}")->assertOk()->json('data.images.0');
        $this->assertSame("http://10.0.2.2:8000/storage/products/{$product->id}/photo-lg.webp", $image['url']);
        $this->assertStringContainsString("http://10.0.2.2:8000/storage/products/{$product->id}/photo-sm.webp 480w", $image['srcset']);

        $this->assertStringStartsWith('https://preprod.mk-delices.tech/storage/', $this->getJson("https://preprod.mk-delices.tech/api/v1/catalog/products/{$product->slug}")->json('data.images.0.url'));
    }

    public function test_token_login_me_and_logout_without_cookies_or_csrf(): void
    {
        $this->client();
        $response = $this->postJson('/api/v1/auth/token', ['email' => 'AMINATA@example.test', 'password' => 'MotDePasse2026', 'device_name' => 'iPhone Aminata'])
            ->assertCreated()->assertJsonPath('data.user.roles', ['client'])->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'user' => ['id', 'customer']]]);
        $token = $response->json('data.token');
        $this->assertNotNull($response->json('data.expires_at'), 'Les jetons mobiles expirent.');
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $token]); // jamais stocké en clair (empreinte SHA-256)

        $this->api('GET', '/api/v1/auth/me', $token)->assertOk()->assertJsonPath('data.email', 'aminata@example.test');
        // Écriture sans jeton CSRF : acceptée pour un client mobile authentifié par jeton.
        $this->api('POST', '/api/v1/account/addresses', $token, ['label' => 'Maison', 'zone_id' => $this->zone()->id, 'district' => 'Mermoz'])->assertCreated();

        $this->api('POST', '/api/v1/auth/logout', $token)->assertNoContent();
        $this->api('GET', '/api/v1/auth/me', $token)->assertUnauthorized();
    }

    public function test_token_login_rules(): void
    {
        $user = $this->staff(User::ROLE_COMMERCIAL);
        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'Password-123'])->assertStatus(422);
        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'mauvais', 'device_name' => 'Test'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $this->postJson('/api/v1/auth/token', ['email' => 'inconnu@example.test', 'password' => 'mauvais', 'device_name' => 'Test'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');

        $user->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'Password-123', 'device_name' => 'Test'])->assertForbidden()->assertJsonPath('error.code', 'account_disabled');

        // Même limitation des tentatives que la connexion web.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/token', ['email' => 'cible@example.test', 'password' => 'x', 'device_name' => 'Test'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/token', ['email' => 'cible@example.test', 'password' => 'x', 'device_name' => 'Test'])->assertStatus(429);
    }

    public function test_register_from_mobile_returns_a_client_token(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Moussa', 'email' => 'moussa@example.test', 'phone' => '78 444 55 66', 'device_name' => 'Galaxy A15',
            'password' => 'MotDePasse2026', 'password_confirmation' => 'MotDePasse2026', 'accepted_privacy' => true,
        ])->assertCreated()->assertJsonPath('data.user.roles', ['client'])->json('data.token');

        $this->api('GET', '/api/v1/account/orders', $token)->assertOk()->assertJsonPath('data', []);
    }

    public function test_mobile_customer_order_is_linked_to_account_and_viewable_without_tracking_token(): void
    {
        $user = $this->client();
        $token = $this->token('aminata@example.test', 'MotDePasse2026');
        $variant = $this->variant();
        $zone = $this->zone();

        $cart = $this->api('POST', '/api/v1/cart', $token)->assertCreated()->json('data');
        $headers = ['X-Cart-Id' => $cart['cart_id'], 'X-Cart-Token' => $cart['cart_token']];
        $this->api('POST', '/api/v1/cart/items', $token, ['variant_id' => $variant->id, 'quantity' => 2], $headers)->assertOk();
        $slot = $this->api('POST', '/api/v1/checkout/quote', $token, ['zone_id' => $zone->id], $headers)->assertOk()->json('data.slots.0.start_at');
        $reference = $this->api('POST', '/api/v1/checkout/orders', $token, $this->orderPayload($zone, $slot), $headers + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->json('data.reference');

        $order = Order::query()->where('reference', $reference)->firstOrFail();
        $this->assertSame($user->customer->id, $order->customer_id, 'La commande passée depuis l\'application est rattachée au compte.');
        $this->api('GET', '/api/v1/account/orders', $token)->assertOk()->assertJsonPath('data.0.reference', $reference);
        $this->api('GET', "/api/v1/orders/{$reference}/status", $token)->assertOk()->assertJsonPath('data.reference', $reference);

        // Un autre client mobile ne voit pas cette commande.
        $other = $this->staff(User::ROLE_CLIENT);
        $other->customer()->create(['first_name' => 'Autre', 'phone' => '+221770000001']);
        $this->api('GET', "/api/v1/orders/{$reference}/status", $this->token($other->email))->assertNotFound();
    }

    public function test_permissions_apply_to_tokens_like_to_sessions(): void
    {
        $partner = $this->partner($this->zone());
        $courierToken = $this->token($partner->user->email);
        $this->api('GET', '/api/v1/courier/missions', $courierToken)->assertOk();
        $this->api('GET', '/api/v1/admin/orders', $courierToken)->assertForbidden();

        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->api('GET', '/api/v1/admin/orders', $this->token($commercial->email))->assertOk();
    }

    public function test_devices_can_be_listed_and_revoked_and_are_capped(): void
    {
        $user = $this->staff(User::ROLE_COMMERCIAL);
        $phone = $this->token($user->email, device: 'Téléphone');
        $tablet = $this->token($user->email, device: 'Tablette');

        $devices = $this->api('GET', '/api/v1/auth/tokens', $phone)->assertOk()->json('data');
        $this->assertCount(2, $devices);
        $tabletId = collect($devices)->firstWhere('device_name', 'Tablette')['id'];
        $this->assertTrue(collect($devices)->firstWhere('device_name', 'Téléphone')['current']);

        $this->api('DELETE', "/api/v1/auth/tokens/{$tabletId}", $phone)->assertNoContent();
        $this->api('GET', '/api/v1/auth/me', $tablet)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', $phone)->assertOk();

        $this->withoutMiddleware(ThrottleRequests::class); // 12 connexions d'affilée : hors limitation pour ce test
        for ($i = 0; $i < 12; $i++) {
            $this->token($user->email, device: "Appareil {$i}");
        }
        $this->assertSame(10, $user->tokens()->count(), 'Au plus 10 appareils connectés par compte.');
    }

    public function test_tokens_are_revoked_on_deactivation_password_change_and_expiry(): void
    {
        $user = $this->staff(User::ROLE_COMMERCIAL);
        $token = $this->token($user->email);
        $user->update(['password' => 'NouveauMotDePasse2026']);
        $this->api('GET', '/api/v1/auth/me', $token)->assertUnauthorized();

        $token = $this->token($user->email, 'NouveauMotDePasse2026');
        $user->update(['is_active' => false]);
        $this->assertSame(0, $user->tokens()->count());
        $this->api('GET', '/api/v1/auth/me', $token)->assertUnauthorized();

        $user->update(['is_active' => true]);
        $token = $this->token($user->email, 'NouveauMotDePasse2026');
        $this->travel(31)->days();
        $this->api('GET', '/api/v1/auth/me', $token)->assertUnauthorized();
    }

    public function test_web_session_login_still_works_for_the_spa(): void
    {
        $this->withHeader('Referer', 'http://localhost:5173/');
        $user = $this->staff(User::ROLE_COMMERCIAL);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-123'])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->assertSame(0, $user->tokens()->count(), 'Le site web n\'utilise jamais de jeton.');
    }
}
