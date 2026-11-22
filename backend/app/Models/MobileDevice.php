<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Téléphone enregistré pour les notifications push de l'application mobile (jeton FCM). */
class MobileDevice extends Model
{
    use HasUuids;

    public const PLATFORMS = ['android', 'ios'];

    protected $fillable = ['user_id', 'token', 'token_hash', 'platform', 'app_version', 'access_token_id', 'last_success_at'];

    /** Le jeton d'appareil n'est jamais renvoyé par l'API. */
    protected $hidden = ['token', 'token_hash', 'access_token_id'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_success_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
