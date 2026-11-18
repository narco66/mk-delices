<?php

namespace Tests\Feature;

use App\Domain\Shared\AuditLogger;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** SEC-009 — T-009, T-013, T-019 et contrôles transverses */
class SecurityTest extends TestCase
{
    public function test_private_endpoints_require_authentication(): void
    {
        foreach (['/api/v1/admin/orders', '/api/v1/courier/missions', '/api/v1/account/orders', '/api/v1/auth/me'] as $uri) {
            $this->getJson($uri)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
            // Audit sécurité du 18/11/2026 : sans « Accept: application/json », 401 et non une erreur 500.
            $this->get($uri)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        }
    }

    public function test_role_matrix_is_enforced_server_side(): void
    {
        $matrix = [
            User::ROLE_PREPARATEUR => ['/api/v1/admin/orders' => 403, '/api/v1/admin/preparation' => 200, '/api/v1/admin/payments' => 403, '/api/v1/admin/users' => 403],
            User::ROLE_RESP_LIVRAISON => ['/api/v1/admin/delivery/board' => 200, '/api/v1/admin/stats?from=2026-01-01&to=2026-01-31' => 403, '/api/v1/admin/products' => 403],
            User::ROLE_COMMERCIAL => ['/api/v1/admin/orders' => 200, '/api/v1/admin/stats?from=2026-01-01&to=2026-01-31' => 200, '/api/v1/admin/users' => 403, '/api/v1/admin/settings' => 403],
            User::ROLE_LIVREUR => ['/api/v1/admin/orders' => 403, '/api/v1/admin/stats?from=2026-01-01&to=2026-01-31' => 403, '/api/v1/admin/dashboard' => 403],
            User::ROLE_CLIENT => ['/api/v1/admin/dashboard' => 403, '/api/v1/courier/missions' => 403],
            User::ROLE_ADMIN => ['/api/v1/admin/users' => 200, '/api/v1/admin/settings' => 200, '/api/v1/admin/audit-logs' => 200],
        ];
        foreach ($matrix as $role => $checks) {
            $user = $this->staff($role);
            foreach ($checks as $uri => $status) {
                $this->actingAs($user)->getJson($uri)->assertStatus($status);
            }
        }
    }

    public function test_public_registration_never_grants_staff_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Nouveau', 'email' => 'nouveau@test.local', 'phone' => '770001122',
            'password' => 'MotDePasse123', 'password_confirmation' => 'MotDePasse123', 'accepted_privacy' => true, 'roles' => ['admin'],
        ])->assertCreated()->assertJsonPath('data.roles', ['client'])->assertJsonPath('data.is_staff', false)
            ->assertJsonPath('data.customer.marketing_consent', false);
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    public function test_login_success_failure_disabled_account_and_rate_limit(): void
    {
        $user = $this->staff(User::ROLE_COMMERCIAL);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-123'])->assertOk()->assertJsonPath('data.roles', ['commercial']);

        $disabled = $this->staff(User::ROLE_COMMERCIAL);
        $disabled->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $disabled->email, 'password' => 'Password-123'])->assertForbidden();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@test.local', 'password' => 'mauvais'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@test.local', 'password' => 'mauvais'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
    }

    /** Audit E1 : un en-tête X-Forwarded-For forgé ne doit ni changer l'IP retenue ni contourner la limitation. */
    public function test_forged_forwarded_for_header_does_not_bypass_login_throttling(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'cible@test.local', 'password' => 'essai-'.$i], ['X-Forwarded-For' => "203.0.113.{$i}"])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'cible@test.local', 'password' => 'essai-6'], ['X-Forwarded-For' => '198.51.100.77'])
            ->assertStatus(429);
        $this->assertSame(0, AuditLog::query()->where('ip_address', 'like', '203.0.113.%')->count(), "IP forgée enregistrée dans le journal d'audit");
    }

    /** Audit E11 : en préproduction, l'administrateur de démonstration n'a jamais le mot de passe public. */
    public function test_demo_admin_never_gets_public_password_outside_local(): void
    {
        $this->app['env'] = 'staging';
        try {
            $this->seed(DemoDataSeeder::class);
        } finally {
            $this->app['env'] = 'testing';
        }
        $admin = User::query()->where('email', 'admin@mk-delices.test')->firstOrFail();
        $this->assertFalse(Hash::check(DemoDataSeeder::DEMO_PASSWORD, $admin->password));
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@mk-delices.test', 'password' => DemoDataSeeder::DEMO_PASSWORD])->assertStatus(401);
        // Les comptes aux rôles limités gardent le mot de passe commun de démonstration.
        $commercial = User::query()->where('email', 'commercial@mk-delices.test')->firstOrFail();
        $this->assertTrue(Hash::check(DemoDataSeeder::DEMO_PASSWORD, $commercial->password));
    }

    /** Lot 3 : le préparateur ne voit que les commandes en production, sans données d'identification superflues. */
    public function test_preparer_sees_only_orders_in_production_with_reduced_data(): void
    {
        [$order, $token] = $this->createOrder(null, null, ['customer' => ['last_name' => 'Diop']]);
        $prep = $this->staff(User::ROLE_PREPARATEUR);
        $this->actingAs($prep)->getJson("/api/v1/admin/orders/{$order->id}")->assertNotFound(); // pas encore acceptée

        $this->payOrder($order, $token);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept')->assertOk();
        $this->actingAs($prep)->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.customer.first_name', 'Awa')->assertJsonPath('data.customer.last_name', null)
            ->assertJsonMissingPath('data.customer_id')->assertJsonMissingPath('data.events')->assertJsonMissingPath('data.assignments');
    }

    /** Lot 3 : l'écran Utilisateurs ne gère que l'équipe ; les changements de mot de passe sont tracés. */
    public function test_user_management_is_limited_to_staff_and_audits_password_changes(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $client = $this->staff(User::ROLE_CLIENT);
        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$client->id}", ['name' => 'X', 'email' => $client->email, 'roles' => ['admin'], 'password' => 'NouveauMotDePasse2026'])
            ->assertStatus(422)->assertJsonPath('error.code', 'not_staff_account');
        $this->assertFalse($client->fresh()->hasRole(User::ROLE_ADMIN));

        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->putJson("/api/v1/admin/users/{$commercial->id}", ['name' => 'Commercial', 'email' => $commercial->email, 'roles' => ['commercial'], 'password' => 'NouveauMotDePasse2026'])->assertOk();
        $log = AuditLog::query()->where('action', 'user.updated')->latest('created_at')->firstOrFail();
        $this->assertTrue($log->after_json['password_changed']);
        $this->assertStringNotContainsString('NouveauMotDePasse2026', json_encode($log->after_json));
    }

    /** Lot 3 : compte client désactivé → plus d'accès par le compte (le lien de suivi personnel reste valable). */
    public function test_disabled_client_loses_account_access_to_orders(): void
    {
        [$order] = $this->createOrder();
        $user = $this->staff(User::ROLE_CLIENT);
        $customer = $user->customer()->create(['first_name' => 'Awa', 'phone' => '+221771234567']);
        $order->update(['customer_id' => $customer->id]);
        $this->actingAs($user)->getJson("/api/v1/orders/{$order->reference}/status")->assertOk();
        $user->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh())->getJson("/api/v1/orders/{$order->reference}/status")->assertNotFound();
    }

    public function test_malformed_inputs_return_4xx_and_api_is_not_cached(): void
    {
        $admin = $this->staff(User::ROLE_ADMIN);
        $this->actingAs($admin)->getJson('/api/v1/admin/refunds?status[]=requested')->assertStatus(422);
        $this->getJson('/api/v1/admin/invoices/pas-un-uuid/pdf')->assertNotFound();
        $this->getJson('/api/v1/admin/refunds')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_disabled_user_loses_access_immediately(): void
    {
        $user = $this->staff(User::ROLE_COMMERCIAL);
        $user->update(['is_active' => false]);
        $this->actingAs($user)->getJson('/api/v1/admin/orders')->assertForbidden()->assertJsonPath('error.code', 'account_disabled');
    }

    public function test_security_headers_and_request_id_are_present(): void
    {
        $this->getJson('/api/v1/config')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderMissing('X-Powered-By-Laravel')
            ->assertHeader('X-Request-Id');
    }

    public function test_cart_cannot_be_read_without_its_secret(): void
    {
        [$id] = $this->cartWith($this->variant());
        $this->getJson('/api/v1/cart', ['X-Cart-Id' => $id, 'X-Cart-Token' => 'devine'])->assertNotFound();
        $this->getJson('/api/v1/cart', ['X-Cart-Id' => 'pas-un-uuid', 'X-Cart-Token' => 'x'])->assertNotFound();
    }

    public function test_malformed_identifiers_return_404_not_500(): void
    {
        $this->actingAs($this->staff(User::ROLE_ADMIN))->getJson('/api/v1/admin/orders/abc')->assertNotFound();
    }

    public function test_audit_log_is_immutable(): void
    {
        $log = app(AuditLogger::class)->log('test.action');
        $this->expectException(QueryException::class);
        AuditLog::query()->whereKey($log->id)->delete();
    }

    public function test_courier_and_preparer_see_masked_customer_contact_on_order(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept')->assertOk();
        $this->actingAs($this->staff(User::ROLE_PREPARATEUR));
        // Le préparateur accède au détail (commande en production) via preparation.manage, sans coordonnées complètes.
        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.customer.phone', '+22177 *** ** 67')->assertJsonPath('data.customer.email', null)
            ->assertJsonMissingPath('data.address.landmark');
    }

    public function test_simulator_is_unavailable_outside_test_environments(): void
    {
        config(['mkdelices.payments.simulator_enabled' => false]);
        $this->getJson('/api/v1/dev/payment-simulator/SIM-XXXX')->assertNotFound();
        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.payment_methods', []);
    }
}
