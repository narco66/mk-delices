<?php

namespace App\Domain\Shared;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /** Champs jamais conservés dans le journal. */
    private const REDACTED = ['password', 'remember_token', 'tracking_token_hash', 'guest_token_hash', 'api_key', 'secret'];

    public function log(string $action, ?Model $target = null, ?array $before = null, ?array $after = null, ?string $actorId = null): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actorId ?? Auth::id(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'before_json' => $before ? $this->redact($before) : null,
            'after_json' => $after ? $this->redact($after) : null,
            'ip_address' => request()?->ip(),
            'request_id' => RequestContext::id(),
            'created_at' => now(),
        ]);
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::REDACTED, true)) {
                $data[$key] = '[expurgé]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
