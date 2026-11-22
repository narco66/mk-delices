<?php

namespace Tests\Feature;

use App\Domain\Payments\Gateways\GatewaySession;
use App\Domain\Payments\Gateways\GatewayUnavailable;
use App\Domain\Payments\Gateways\SimulatorGateway;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reporting\StatsService;
use App\Models\AuditLog;
use App\Models\MobileDevice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** Lot 2 de l'audit du 22/11/2026 (docs/AUDIT-COMPLET-2026-11-22.md §10). */
class AuditLot2Test extends TestCase
{
    private const PASSWORD = 'MotDePasse2026';

    private function client(string $email = 'aminata@example.test', string $phone = '77 111 22 33'): User
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Aminata', 'email' => $email, 'phone' => $phone,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'accepted_privacy' => true,
        ])->assertCreated();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return User::query()->where('email', mb_strtolower($email))->firstOrFail();
    }

    // ---- S-10 : limite de tentatives par compte ----------------------------------------------------

    public function test_password_guessing_on_one_account_is_capped_whatever_the_ip_and_phone_format(): void
    {
        $this->client();
        $formats = ['771112233', '77 111 22 33', '+221771112233', '00221 77 111 22 33'];
        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.{$i}.1"])
                ->postJson('/api/v1/auth/login', ['login' => $formats[$i % 4], 'password' => 'mauvais'.$i])->assertStatus(401);
        }
        // 31e essai, depuis une nouvelle adresse et avec le BON mot de passe : refusé pour l'heure.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson('/api/v1/auth/login', ['login' => '77-111-22-33', 'password' => self::PASSWORD])
            ->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
        // Les autres comptes ne sont pas touchés.
        $this->client('awa@example.test', '78 222 33 44');
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson('/api/v1/auth/login', ['login' => '782223344', 'password' => self::PASSWORD])->assertOk();
    }

    // ---- S-13 : un numéro = un compte, y compris depuis le back-office ------------------------------

    public function test_back_office_normalises_staff_and_courier_phones_and_keeps_them_unique(): void
    {
        $this->client();
        $admin = $this->staff(User::ROLE_ADMIN);
        $payload = ['name' => 'Moussa Diop', 'email' => 'Moussa@MK.test', 'phone' => '77 111 22 33', 'password' => 'MotDePasse-Equipe1', 'roles' => [User::ROLE_COMMERCIAL]];

        $this->actingAs($admin)->postJson('/api/v1/admin/users', $payload)->assertStatus(422)->assertJsonPath('error.code', 'phone_taken');
        $this->actingAs($admin)->postJson('/api/v1/admin/users', ['phone' => '12'] + $payload)->assertStatus(422)->assertJsonPath('error.code', 'invalid_phone');
        $created = $this->actingAs($admin)->postJson('/api/v1/admin/users', ['phone' => '76 555 44 33'] + $payload)->assertCreated();
        $this->assertSame('+221765554433', $created->json('data.phone'));
        $this->assertSame('moussa@mk.test', $created->json('data.email'));

        $zone = $this->zone();
        $this->actingAs($admin)->postJson('/api/v1/admin/delivery/partners', [
            'display_name' => 'Ibou', 'phone' => '+221 76 555 44 33', 'email' => 'ibou@mk.test', 'password' => 'Livreur2026',
            'transport_mode' => 'moto', 'zone_ids' => [$zone->id],
        ])->assertStatus(422)->assertJsonPath('error.code', 'phone_taken');

        // Garde-fou en base : même un enregistrement direct ne peut pas dupliquer un numéro actif.
        $this->expectException(QueryException::class);
        User::query()->create(['name' => 'Doublon', 'email' => 'doublon@example.test', 'phone' => '+221771112233', 'password' => self::PASSWORD]);
    }

    // ---- S-14 : email en majuscules -----------------------------------------------------------------

    public function test_registering_an_existing_email_in_capitals_gets_a_clear_message(): void
    {
        $this->client();
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Awa', 'email' => 'AMINATA@Example.test', 'phone' => '78 222 33 44',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'accepted_privacy' => true,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['email']]]);
    }

    // ---- S-15 : mot de passe de l'équipe -------------------------------------------------------------

    public function test_staff_password_reset_keeps_the_staff_password_rule(): void
    {
        $staff = $this->staff(User::ROLE_COMMERCIAL);
        $token = Password::broker()->createToken($staff);
        $reset = fn (string $password) => $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $staff->email, 'password' => $password, 'password_confirmation' => $password,
        ]);

        $reset('motdepasse26')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $reset('MotDePasse-Equipe26')->assertOk();
    }

    // ---- S-16 : empreinte à clé secrète --------------------------------------------------------------

    public function test_failed_login_journal_uses_a_keyed_fingerprint(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => '77 111 22 33', 'password' => 'mauvais'])->assertStatus(401);

        $hash = AuditLog::query()->where('action', 'auth.login_failed')->firstOrFail()->after_json['identifier_hash'];
        $this->assertNotSame(hash('sha256', '+221771112233'), $hash);
        $this->assertSame(hash_hmac('sha256', '+221771112233', (string) config('app.key')), $hash);
    }

    // ---- S-18 : téléphones des notifications -----------------------------------------------------------

    public function test_push_devices_are_registered_from_the_app_only_and_reassignments_are_logged(): void
    {
        $aminata = $this->client();
        $awa = $this->client('awa@example.test', '78 222 33 44');
        $device = ['token' => str_repeat('d', 152), 'platform' => 'android'];

        $this->actingAs($aminata)->postJson('/api/v1/account/devices', $device)->assertForbidden()->assertJsonPath('error.code', 'mobile_app_only');

        $tokenFor = function (string $login) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();

            return $this->withoutHeader('Referer')->postJson('/api/v1/auth/token', ['login' => $login, 'password' => self::PASSWORD, 'device_name' => 'Pixel'])->json('data.token');
        };
        $first = $tokenFor('aminata@example.test');
        $second = $tokenFor('awa@example.test');
        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Referer')->postJson('/api/v1/account/devices', $device, ['Authorization' => "Bearer {$first}"])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Referer')->postJson('/api/v1/account/devices', $device, ['Authorization' => "Bearer {$second}"])->assertOk();

        $this->assertSame($awa->id, MobileDevice::query()->sole()->user_id);
        $log = AuditLog::query()->where('action', 'device.reassigned')->sole();
        $this->assertSame(['user_id' => $aminata->id], $log->before_json);
        $this->assertSame(['user_id' => $awa->id], $log->after_json);
    }

    // ---- A-04 / A-05 : retrait sur place et brouillons WhatsApp ----------------------------------------

    public function test_a_pickup_order_cannot_receive_a_delivery_zone(): void
    {
        [$order] = $this->createOrder();
        $order->forceFill(['fulfillment_type' => 'pickup', 'zone_id' => null, 'delivery_fee_xof' => 0, 'total_xof' => $order->subtotal_xof])->save();

        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->patchJson("/api/v1/admin/orders/{$order->id}/details", [
            'delivery' => ['zone_id' => $this->zone()->id, 'district' => 'Plateau'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'pickup_has_no_zone');
        $this->assertSame(0, $order->fresh()->delivery_fee_xof);
    }

    public function test_confirming_a_whatsapp_draft_applies_the_same_zone_rules_as_online_orders(): void
    {
        $variant = $this->variant(['price_xof' => 2500, 'prep_minutes' => 60]);
        $zone = $this->zone(['min_order_xof' => 4000]);
        $cart = $this->cartWith($variant, 1);
        $reference = $this->postJson('/api/v1/checkout/whatsapp-draft', [], $this->cartHeaders(...$cart))->json('data.reference');
        $slot = $this->firstSlot($cart);
        $order = Order::query()->where('reference', $reference)->firstOrFail();
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->actingAs($commercial)->patchJson("/api/v1/admin/orders/{$order->id}/details", [
            'customer' => ['first_name' => 'Fatou', 'phone' => '781234567'],
            'delivery' => ['zone_id' => $zone->id, 'district' => 'Plateau'],
            'slot_start_at' => $slot,
        ])->assertOk();

        // Minimum de la zone non atteint (2 500 < 4 000).
        $this->transition($commercial, $order, 'submit')->assertStatus(422)->assertJsonPath('error.code', 'min_order_not_reached');
        // Zone désactivée entre-temps.
        $zone->update(['min_order_xof' => null, 'active' => false]);
        $this->transition($commercial, $order, 'submit')->assertStatus(422)->assertJsonPath('error.code', 'zone_unavailable');
        $this->assertSame('draft', $order->fresh()->status->value);

        // Délai de préparation du jour repris dans la commande confirmée.
        $zone->update(['active' => true]);
        $variant->update(['prep_minutes' => 90]);
        $this->transition($commercial, $order, 'submit')->assertOk();
        $this->assertSame(90, $order->items()->sole()->prep_minutes_snapshot);
    }

    // ---- A-06 : prestataire appelé hors verrou --------------------------------------------------------

    public function test_unavailable_provider_leaves_no_half_open_payment_and_a_retry_works(): void
    {
        [$order, $token] = $this->createOrder();
        $this->app->instance(SimulatorGateway::class, new class extends SimulatorGateway
        {
            public function createSession(Payment $payment, Order $order): GatewaySession
            {
                throw new GatewayUnavailable('Prestataire injoignable');
            }
        });
        $this->postJson("/api/v1/orders/{$order->reference}/payments", ['method' => 'simulator'], ['X-Tracking-Token' => $token, 'Idempotency-Key' => 'pay-down-000001'])
            ->assertStatus(503)->assertJsonPath('error.code', 'psp_unavailable');
        $this->assertSame(0, Payment::query()->count());

        $this->app->forgetInstance(SimulatorGateway::class);
        $this->postJson("/api/v1/orders/{$order->reference}/payments", ['method' => 'simulator'], ['X-Tracking-Token' => $token, 'Idempotency-Key' => 'pay-down-000001'])->assertCreated();
        $this->assertSame('pending', Payment::query()->sole()->status->value);
    }

    public function test_a_payment_being_opened_is_never_opened_twice(): void
    {
        [$order, $token] = $this->createOrder();
        $inFlight = Payment::query()->create([
            'order_id' => $order->id, 'provider' => 'simulator', 'status' => PaymentStatus::Initiated,
            'amount_xof' => $order->total_xof, 'currency' => 'XOF', 'idempotency_key' => 'pay-flight-00001', 'is_simulated' => true,
        ]);
        $pay = fn (string $key) => $this->postJson("/api/v1/orders/{$order->reference}/payments", ['method' => 'simulator'], ['X-Tracking-Token' => $token, 'Idempotency-Key' => $key]);

        // Une autre requête est en train d'appeler le prestataire : pas de seconde session.
        $pay('pay-flight-00002')->assertStatus(409)->assertJsonPath('error.code', 'payment_starting');
        $pay('pay-flight-00001')->assertStatus(409)->assertJsonPath('error.code', 'payment_starting');

        // Tentative interrompue (processus arrêté) : close, puis une nouvelle session est ouverte.
        $inFlight->forceFill(['created_at' => now()->subMinutes(5)])->save();
        $pay('pay-flight-00002')->assertCreated();
        $this->assertSame('failed', $inFlight->fresh()->status->value);
        $this->assertSame(1, Payment::query()->where('status', 'pending')->count());
    }

    // ---- A-08 : commandes de test hors tableau de bord ---------------------------------------------------

    public function test_dashboard_refund_counter_ignores_test_orders(): void
    {
        [$real] = $this->createOrder();
        [$test] = $this->createOrder();
        $test->forceFill(['is_test' => true])->save();
        foreach ([$real, $test] as $order) {
            $payment = Payment::query()->create(['order_id' => $order->id, 'provider' => 'simulator', 'status' => PaymentStatus::Succeeded, 'amount_xof' => 1000, 'currency' => 'XOF', 'idempotency_key' => 'pay-'.$order->id, 'is_simulated' => true]);
            Refund::query()->create(['order_id' => $order->id, 'payment_id' => $payment->id, 'amount_xof' => 1000, 'status' => 'requested', 'reason' => 'Test', 'kind' => 'sale']);
        }

        $this->assertSame(1, app(StatsService::class)->operations()['refunds_pending']);
    }

    // ---- Contrôle de mise en production ------------------------------------------------------------------

    public function test_go_live_check_reports_the_online_payment_switch(): void
    {
        $this->artisan('mk:go-live-check')
            ->expectsOutputToContain('payments.online_enabled, une fois Wave contractualisé');
    }
}
