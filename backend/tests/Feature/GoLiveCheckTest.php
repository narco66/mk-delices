<?php

namespace Tests\Feature;

use App\Domain\Shared\Settings;
use App\Models\User;
use Tests\TestCase;

/** Vérification « prêt pour la production » (GO-NO-GO-PRODUCTION.md) : lecture seule, code 1 si un point bloque. */
class GoLiveCheckTest extends TestCase
{
    public function test_blocks_opening_while_demo_accounts_and_missing_legal_data_remain(): void
    {
        User::query()->create(['name' => 'Démo', 'email' => 'admin@mk-delices.test', 'password' => 'Password-123', 'is_active' => true]);
        $users = User::query()->count();

        $this->artisan('mk:go-live-check')
            ->expectsOutputToContain('1 compte(s) @mk-delices.test actif(s)')
            ->expectsOutputToContain('business.legal_name, business.ninea, business.rccm')
            ->expectsOutputToContain('ouverture au public NON autorisée')
            ->assertFailed();

        $this->assertSame($users, User::query()->count(), 'aucune modification');
    }

    public function test_passes_when_every_machine_checkable_point_is_met(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false, 'app.url' => 'https://mk-delices.example', 'mail.default' => 'smtp',
            'mkdelices.payments.simulator_enabled' => false,
        ]);
        $settings = app(Settings::class);
        foreach (['business.legal_name' => 'MK Délices SARL', 'business.ninea' => '000000000', 'business.rccm' => 'SN-DKR-0000-B-0000'] as $key => $value) {
            $settings->set($key, $value);
        }
        $settings->set('payments.methods', ['cash_on_delivery' => true]);
        $this->staff(User::ROLE_ADMIN);
        $this->zone();

        $this->artisan('mk:go-live-check')
            ->expectsOutputToContain('Aucun point bloquant vérifiable par la machine')
            ->assertSuccessful();
    }
}
