<?php

namespace App\Http\Controllers;

use App\Domain\Payments\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Santé de l'application pour la supervision externe (audit E9) : 200 si tout va bien, 503 sinon.
 * Ne renvoie que des indicateurs techniques (aucune donnée personnelle). /up (Laravel) ne vérifie
 * que le démarrage du framework ; /health vérifie ce qui fait réellement tourner la boutique.
 */
class HealthController extends Controller
{
    /** Clé posée chaque minute par le planificateur (routes/console.php). */
    public const SCHEDULER_HEARTBEAT = 'mk:health:scheduler';

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1') !== []),
            'cache' => $this->check(function () {
                Cache::put('mk:health:probe', $value = (string) microtime(true), 60);

                return Cache::get('mk:health:probe') === $value;
            }),
            // File de tâches : une tâche en attente depuis plus de 10 min = worker arrêté ou saturé.
            'queue' => $this->check(fn () => DB::table('jobs')->where('available_at', '<', now()->subMinutes(10)->getTimestamp())->doesntExist()),
            'failed_jobs_24h' => $this->check(fn () => DB::table('failed_jobs')->where('failed_at', '>', now()->subDay())->doesntExist()),
            // Paiements jamais confirmés ni clos depuis plus de 2 h : rapprochement à examiner.
            'payments_pending' => $this->check(fn () => DB::table('payments')
                ->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])
                ->where('created_at', '<', now()->subHours(2))->doesntExist()),
        ];
        if (config('mkdelices.health.require_scheduler')) {
            $checks['scheduler'] = $this->check(fn () => ($beat = Cache::get(self::SCHEDULER_HEARTBEAT)) !== null && $beat > now()->subMinutes(10)->getTimestamp());
        }
        if ($file = config('mkdelices.health.backup_status_file')) {
            // Fichier horodaté écrit par deploy/scripts/backup.sh après chaque sauvegarde réussie.
            $checks['backup'] = $this->check(fn () => is_readable($file) && (int) trim((string) file_get_contents($file)) > now()->subHours(26)->getTimestamp());
        }

        $ok = ! in_array(false, $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
