<?php

namespace Tests\Feature;

use App\Domain\Customers\Push\MobilePushTransport;
use App\Domain\Shared\Settings;
use App\Models\MobileDevice;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fonctions du compte (22/11/2026) : connexion par téléphone, mot de passe oublié, suppression du compte,
 * favoris, « recommander », Club MK, téléphones enregistrés pour les notifications push, paiement réel suspendu.
 */
class AccountFeaturesTest extends TestCase
{
    private const PASSWORD = 'MotDePasse2026';

    private function client(string $email = 'aminata@example.test', string $phone = '77 111 22 33'): User
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Aminata', 'last_name' => 'Fall', 'email' => $email, 'phone' => $phone,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'accepted_privacy' => true,
        ])->assertCreated();

        return User::query()->where('email', $email)->firstOrFail();
    }

    /** Nouvelle visite sans session (équivalent d'une déconnexion). */
    private function signOut(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    /** Requête « application mobile » : jeton Bearer, sans session ni cookie. */
    private function mobile(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withoutHeader('Referer')->json($method, $uri, $data, ['Authorization' => "Bearer {$token}"]);
    }

    private function mobileToken(string $login): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutHeader('Referer')->postJson('/api/v1/auth/token', ['login' => $login, 'password' => self::PASSWORD, 'device_name' => 'Pixel test'])
            ->assertCreated()->json('data.token');
    }

    /** Commande passée par le client connecté (rattachée à son compte), puis amenée à l'état voulu. */
    private function orderFor(User $user, string $status = 'delivered'): Order
    {
        $this->actingAs($user);
        [$order] = $this->createOrder();
        $order->forceFill(['status' => $status])->save();

        return $order->fresh();
    }

    // ---- Connexion par téléphone -----------------------------------------------------------------

    public function test_login_with_phone_number_on_web_and_mobile(): void
    {
        $user = $this->client();
        $this->signOut();

        // Formats saisis par les clients : local, avec espaces, avec indicatif.
        $this->postJson('/api/v1/auth/login', ['login' => '77 111 22 33', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->signOut();
        $this->postJson('/api/v1/auth/login', ['login' => 'aminata@example.test', 'password' => self::PASSWORD])->assertOk();

        $this->assertNotEmpty($this->mobileToken('+221771112233'));
        $this->withoutHeader('Referer')->postJson('/api/v1/auth/token', ['phone' => '771112233', 'password' => 'mauvais', 'device_name' => 'Pixel'])
            ->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $this->postJson('/api/v1/auth/login', ['login' => 'aminata', 'password' => self::PASSWORD])
            ->assertStatus(401)->assertJsonPath('error.message', 'Saisissez votre adresse email ou votre numéro de téléphone (ex. 77 123 45 67).');
        // L'ancien format (champ « email ») reste accepté.
        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Referer')->postJson('/api/v1/auth/token', ['email' => 'aminata@example.test', 'password' => self::PASSWORD, 'device_name' => 'Pixel'])->assertCreated();
    }

    public function test_a_phone_number_belongs_to_a_single_account(): void
    {
        $this->client();
        $this->signOut();
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Awa', 'email' => 'awa@example.test', 'phone' => '+221 77 111 22 33',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'accepted_privacy' => true,
        ])->assertStatus(422)->assertJsonPath('error.code', 'phone_taken');
    }

    // ---- Mot de passe oublié ---------------------------------------------------------------------

    public function test_forgot_password_sends_a_single_use_link_and_disconnects_devices(): void
    {
        Notification::fake();
        $user = $this->client();
        $token = $this->mobileToken('aminata@example.test');

        // Même réponse pour un compte inconnu (pas d'énumération).
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@example.test'])->assertStatus(202)->json('data.message');
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'AMINATA@example.test'])->assertStatus(202)->json('data.message');
        $this->assertSame($unknown, $known);

        $url = null;
        Notification::assertSentTo($user, PasswordResetLink::class, function (PasswordResetLink $n) use ($user, &$url) {
            $url = $n->url($user);

            return true;
        });
        $this->assertStringStartsWith(config('mkdelices.frontend_url').'/mot-de-passe/nouveau?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => 'faux', 'password' => 'NouveauMot2026', 'password_confirmation' => 'NouveauMot2026'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_reset_link');
        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'password' => 'NouveauMot2026', 'password_confirmation' => 'NouveauMot2026'])->assertOk();
        // Lien à usage unique.
        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'password' => 'AutreMot2026', 'password_confirmation' => 'AutreMot2026'])->assertStatus(422);

        $this->mobile('GET', '/api/v1/auth/me', $token)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['login' => 'aminata@example.test', 'password' => 'NouveauMot2026'])->assertOk();
    }

    // ---- Suppression du compte -------------------------------------------------------------------

    public function test_account_deletion_anonymizes_and_keeps_orders(): void
    {
        $user = $this->client();
        $order = $this->orderFor($user, 'delivered');
        $customer = $user->customer;
        Review::query()->create(['order_id' => $order->id, 'product_id' => $order->items()->first()->product_id, 'customer_id' => $customer->id, 'author_name' => 'Aminata F.', 'stars' => 5]);
        $token = $this->mobileToken('aminata@example.test');
        $this->mobile('POST', '/api/v1/account/devices', $token, ['token' => str_repeat('a', 40), 'platform' => 'android'])->assertCreated();
        $this->mobile('DELETE', '/api/v1/account', $token, ['password' => 'mauvais'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_password');

        $this->mobile('DELETE', '/api/v1/account', $token, ['password' => self::PASSWORD])->assertNoContent();

        $user->refresh();
        $this->assertNotNull($user->anonymized_at);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email);
        $this->assertNull($user->phone);
        $this->assertSame([], $user->getRoleNames()->all());
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, MobileDevice::query()->count());
        $customer->refresh();
        $this->assertNull($customer->user_id);
        $this->assertSame('Client', $customer->first_name);
        $this->assertSame(0, $customer->addresses()->count());
        $this->assertSame('Client MK Délices', Review::query()->firstOrFail()->author_name);
        // Commande conservée (obligations comptables).
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'customer_id' => $customer->id]);
        // Plus de connexion possible ; le numéro est libéré pour un nouveau compte.
        $this->signOut();
        $this->postJson('/api/v1/auth/login', ['login' => '771112233', 'password' => self::PASSWORD])->assertStatus(401);
        $this->client('nouveau@example.test', '77 111 22 33');
    }

    public function test_account_deletion_waits_for_orders_in_progress_and_refuses_staff(): void
    {
        $user = $this->client();
        $this->orderFor($user, 'accepted');
        $this->actingAs($user)->deleteJson('/api/v1/account', ['password' => self::PASSWORD])->assertStatus(409)->assertJsonPath('error.code', 'active_orders');

        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->deleteJson('/api/v1/account', ['password' => 'Password-123'])
            ->assertStatus(403)->assertJsonPath('error.code', 'staff_account');
    }

    // ---- Favoris ---------------------------------------------------------------------------------

    public function test_favorites_add_list_remove(): void
    {
        $user = $this->client();
        $product = $this->variant()->product;
        $hidden = $this->variant([], ['status' => 'draft'])->product;

        $this->actingAs($user)->postJson('/api/v1/account/favorites', ['product_id' => $product->id])->assertCreated()->assertJsonPath('data.favorite', true);
        $this->postJson('/api/v1/account/favorites', ['product_id' => $product->id])->assertCreated(); // idempotent
        $this->postJson('/api/v1/account/favorites', ['product_id' => $hidden->id])->assertNotFound()->assertJsonPath('error.code', 'product_not_found');
        $this->getJson('/api/v1/account/favorites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $product->slug);

        // Produit retiré de la boutique : il disparaît de la liste sans erreur.
        $product->update(['status' => 'draft']);
        $this->getJson('/api/v1/account/favorites')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/account/favorites/{$product->id}")->assertNoContent();
        $this->assertDatabaseCount('favorites', 0);
        $this->signOut();
        $this->getJson('/api/v1/account/favorites')->assertUnauthorized();
    }

    // ---- Recommander -----------------------------------------------------------------------------

    public function test_reorder_fills_a_new_cart_at_current_prices_and_reports_unavailable_items(): void
    {
        $user = $this->client();
        $kept = $this->variant(['price_xof' => 2500]);
        $gone = $this->variant();
        $this->actingAs($user);
        $cart = $this->cartWith($kept, 2);
        $this->postJson('/api/v1/cart/items', ['variant_id' => $gone->id, 'quantity' => 1], $this->cartHeaders(...$cart))->assertOk();
        $ref = $this->postJson('/api/v1/checkout/orders', $this->orderPayload($this->zone(), $this->firstSlot($cart)), $this->cartHeaders(...$cart) + ['Idempotency-Key' => 'reorder-test-1'])
            ->assertCreated()->json('data.reference');

        $kept->update(['price_xof' => 3000]);
        $gone->update(['available' => false]);
        $data = $this->postJson("/api/v1/account/orders/{$ref}/reorder")->assertOk()->json('data');

        $this->assertNotEmpty($data['cart_token']);
        $this->assertCount(1, $data['lines']);
        $this->assertSame(2, $data['lines'][0]['quantity']);
        $this->assertSame(6000, $data['subtotal_xof']);
        $this->assertCount(1, $data['skipped']);

        // Panier courant fourni : il est complété, pas remplacé.
        $again = $this->postJson("/api/v1/account/orders/{$ref}/reorder", [], $this->cartHeaders($data['cart_id'], $data['cart_token']))->assertOk()->json('data');
        $this->assertSame($data['cart_id'], $again['cart_id']);
        $this->assertArrayNotHasKey('cart_token', $again);
        $this->assertSame(4, $again['lines'][0]['quantity']);

        // Commande d'un autre client : introuvable.
        $this->actingAs($this->client('autre@example.test', '77 999 88 77'))->postJson("/api/v1/account/orders/{$ref}/reorder")->assertNotFound();
    }

    // ---- Club MK ---------------------------------------------------------------------------------

    public function test_club_mk_card_counts_delivered_orders(): void
    {
        $user = $this->client();
        $this->actingAs($user)->getJson('/api/v1/account/loyalty')->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.stamps', 0)->assertJsonPath('data.remaining', 6);

        foreach (range(1, 7) as $i) {
            $this->orderFor($user, 'delivered');
        }
        $this->orderFor($user, 'cancelled');
        $this->actingAs($user)->getJson('/api/v1/account/loyalty')->assertOk()->assertJsonPath('data.delivered_orders', 7)
            ->assertJsonPath('data.stamps', 1)->assertJsonPath('data.remaining', 5)->assertJsonPath('data.rewards_earned', 1)
            ->assertJsonPath('data.reward_label', 'une surprise MK Délices');
        $this->actingAs($this->staff(User::ROLE_ADMIN))->getJson("/api/v1/admin/customers/{$user->customer->id}")->assertJsonPath('data.loyalty.rewards_earned', 1);

        app(Settings::class)->set('loyalty.enabled', false);
        $this->actingAs($user)->getJson('/api/v1/account/loyalty')->assertOk()->assertExactJson(['data' => ['enabled' => false]]);
    }

    // ---- Notifications push mobiles --------------------------------------------------------------

    public function test_device_registration_is_tied_to_the_mobile_session(): void
    {
        $user = $this->client();
        $token = $this->mobileToken('aminata@example.test');
        $fcm = str_repeat('f', 152);

        $id = $this->mobile('POST', '/api/v1/account/devices', $token, ['token' => $fcm, 'platform' => 'android', 'app_version' => '1.0.0'])
            ->assertCreated()->assertJsonMissingPath('data.token')->json('data.id');
        // Même téléphone réenregistré : pas de doublon.
        $this->mobile('POST', '/api/v1/account/devices', $token, ['token' => $fcm, 'platform' => 'android'])->assertOk()->assertJsonPath('data.id', $id);
        $this->mobile('POST', '/api/v1/account/devices', $token, ['token' => $fcm, 'platform' => 'windows'])->assertStatus(422);
        $this->assertNotSame($fcm, MobileDevice::query()->toBase()->value('token'), 'Jeton chiffré en base.');

        // Déconnexion de l'appareil : il ne reçoit plus de notification.
        $this->mobile('POST', '/api/v1/auth/logout', $token)->assertNoContent();
        $this->assertSame(0, $user->mobileDevices()->count());
    }

    public function test_order_updates_are_pushed_to_the_customer_phone_without_personal_data(): void
    {
        $fake = new class implements MobilePushTransport
        {
            public array $messages = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(iterable $devices, array $message): array
            {
                $this->messages[] = $message;
                $results = [];
                foreach ($devices as $device) {
                    $results[$device->id] = str_starts_with($device->token, 'expired') ? 'expired' : 'sent';
                }

                return $results;
            }
        };
        $this->app->instance(MobilePushTransport::class, $fake);

        $user = $this->client();
        $token = $this->mobileToken('aminata@example.test');
        $this->mobile('POST', '/api/v1/account/devices', $token, ['token' => str_repeat('p', 40), 'platform' => 'ios'])->assertCreated();
        $this->mobile('POST', '/api/v1/account/devices', $token, ['token' => 'expired'.str_repeat('x', 40), 'platform' => 'android'])->assertCreated();

        $this->actingAs($user);
        [$order] = $this->createOrder();

        $this->assertNotEmpty($fake->messages);
        $message = $fake->messages[0];
        $this->assertSame('Commande reçue', $message['title']);
        $this->assertSame(['type' => 'order_update', 'event' => 'order.pending_confirmation', 'reference' => $order->reference, 'route' => "/orders/{$order->reference}"], $message['data']);
        $this->assertStringNotContainsString('Aminata', json_encode($message));
        $this->assertStringNotContainsString('+221', json_encode($message));
        // Téléphone désinstallé (jeton expiré) : retiré.
        $this->assertSame(1, $user->mobileDevices()->count());
        $this->assertDatabaseHas('notification_logs', ['order_id' => $order->id, 'channel' => 'push', 'event' => 'push:order.pending_confirmation']);
    }

    public function test_nothing_is_sent_without_firebase_configuration(): void
    {
        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.push.mobile', false);
        $user = $this->client();
        $token = $this->mobileToken('aminata@example.test');
        $this->mobile('POST', '/api/v1/account/devices', $token, ['token' => str_repeat('p', 40), 'platform' => 'ios'])->assertCreated();
        $this->actingAs($user);
        [$order] = $this->createOrder();
        $this->assertDatabaseMissing('notification_logs', ['order_id' => $order->id, 'channel' => 'push']);
    }

    // ---- Paiement réel suspendu ------------------------------------------------------------------

    public function test_online_payment_switch_hides_real_providers_even_when_configured(): void
    {
        config(['mkdelices.payments.wave.api_key' => 'k', 'mkdelices.payments.wave.webhook_secret' => 's']);
        app(Settings::class)->set('payments.methods', ['wave' => true, 'orange_money' => false, 'card' => false, 'cash_on_delivery' => true]);

        $config = $this->getJson('/api/v1/config')->assertOk()->json('data');
        $this->assertFalse($config['online_payment']['enabled']);
        $this->assertNotEmpty($config['online_payment']['notice']);
        $this->assertNotContains('wave', array_column($config['payment_methods'], 'code'));
        $this->assertContains('cash_on_delivery', array_column($config['payment_methods'], 'code'));

        [$order, $tracking] = $this->createOrder();
        $this->postJson("/api/v1/orders/{$order->reference}/payments", ['method' => 'wave'], ['X-Tracking-Token' => $tracking, 'Idempotency-Key' => 'wave-off-1'])
            ->assertStatus(422);

        // Réactivation (contrat signé, clés installées) : Wave redevient proposé.
        $this->actingAs($this->staff(User::ROLE_ADMIN))->putJson('/api/v1/admin/settings', ['values' => ['payments.online_enabled' => true]])->assertOk();
        $this->assertContains('wave', array_column($this->getJson('/api/v1/config')->json('data.payment_methods'), 'code'));
    }
}
