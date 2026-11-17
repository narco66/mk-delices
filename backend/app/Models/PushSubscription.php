<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Abonnement d'un appareil aux notifications du navigateur (web push). */
class PushSubscription extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'endpoint', 'p256dh', 'auth', 'user_agent', 'last_success_at'];

    protected $hidden = ['p256dh', 'auth'];

    protected function casts(): array
    {
        return ['last_success_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
