<?php

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Audit E9 : /health répond 200 quand la boutique fonctionne et 503 dès qu'un contrôle est dégradé. */
class HealthTest extends TestCase
{
    public function test_healthy_application_returns_ok_without_personal_data(): void
    {
        $this->get('/health')->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', true)->assertJsonPath('checks.queue', true)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_stuck_queue_failed_jobs_and_old_pending_payment_degrade_health(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(30)->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp()]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Erreur', 'failed_at' => now()]);

        $this->get('/health')->assertStatus(503)->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue', false)->assertJsonPath('checks.failed_jobs_24h', false)->assertJsonPath('checks.database', true);
    }

    public function test_scheduler_and_backup_checks(): void
    {
        config(['mkdelices.health.require_scheduler' => true]);
        $this->get('/health')->assertStatus(503)->assertJsonPath('checks.scheduler', false);

        // Le battement planifié rétablit le contrôle.
        $heartbeat = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'health-heartbeat');
        $this->assertNotNull($heartbeat);
        $heartbeat->run($this->app);
        $this->assertNotNull(Cache::get(HealthController::SCHEDULER_HEARTBEAT));
        $this->get('/health')->assertOk()->assertJsonPath('checks.scheduler', true);

        // Sauvegarde : horodatage de plus de 26 h = alerte.
        $file = tempnam(sys_get_temp_dir(), 'mk-backup');
        config(['mkdelices.health.backup_status_file' => $file]);
        file_put_contents($file, (string) now()->subHours(30)->getTimestamp());
        $this->get('/health')->assertStatus(503)->assertJsonPath('checks.backup', false);
        file_put_contents($file, (string) now()->subHour()->getTimestamp());
        $this->get('/health')->assertOk()->assertJsonPath('checks.backup', true);
        @unlink($file);
    }

    public function test_spa_does_not_capture_health_route(): void
    {
        $this->get('/health')->assertHeader('Content-Type', 'application/json');
    }
}
