<?php

namespace App\Domain\Shared;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Exécution idempotente : même clé + même charge utile => même résultat ;
 * même clé + charge différente => 409.
 */
class Idempotency
{
    /**
     * @param  Closure(): array{status:int, body:array}  $operation
     * @return array{status:int, body:array, replayed:bool}
     */
    public function run(string $scope, ?string $key, array $payload, Closure $operation, int $ttlHours = 24): array
    {
        if ($key === null || ! preg_match('/^[A-Za-z0-9\-_:.]{8,100}$/', $key)) {
            throw DomainException::unprocessable('idempotency_key_required', 'En-tête Idempotency-Key manquant ou invalide (8 à 100 caractères alphanumériques).');
        }
        $hash = hash('sha256', json_encode($this->normalize($payload)));

        return DB::transaction(function () use ($scope, $key, $hash, $operation, $ttlHours) {
            // Verrou consultatif sur la clé : sérialise les doubles clics simultanés.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["idem:{$scope}:{$key}"]);

            $existing = IdempotencyKey::query()->where('scope', $scope)->where('key', $key)->first();
            if ($existing && $existing->expires_at->isFuture()) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw DomainException::conflict('idempotency_key_reused', 'Cette clé d\'idempotence a déjà été utilisée avec une requête différente.');
                }
                if ($existing->response_status !== null) {
                    return ['status' => $existing->response_status, 'body' => $existing->response_json, 'replayed' => true];
                }
            }
            $existing?->delete();

            $result = $operation();

            try {
                IdempotencyKey::query()->create([
                    'scope' => $scope,
                    'key' => $key,
                    'request_hash' => $hash,
                    'response_status' => $result['status'],
                    'response_json' => $result['body'],
                    'expires_at' => now()->addHours($ttlHours),
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                throw DomainException::conflict('idempotency_conflict', 'Requête concurrente détectée, veuillez réessayer.');
            }

            return $result + ['replayed' => false];
        });
    }

    private function normalize(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $payload[$k] = $this->normalize($v);
            }
        }

        return $payload;
    }
}
