<?php

namespace App\Models;

use App\Notifications\PasswordResetLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_COMMERCIAL = 'commercial';

    public const ROLE_PREPARATEUR = 'preparateur';

    public const ROLE_RESP_LIVRAISON = 'resp_livraison';

    public const ROLE_LIVREUR = 'livreur';

    public const ROLE_CLIENT = 'client';

    public const STAFF_ROLES = [self::ROLE_ADMIN, self::ROLE_COMMERCIAL, self::ROLE_PREPARATEUR, self::ROLE_RESP_LIVRAISON];

    protected $fillable = ['name', 'email', 'phone', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Compte désactivé ou mot de passe changé : tous les appareils mobiles sont déconnectés
     * (jetons révoqués), quel que soit le chemin de modification (back-office, livreurs, commandes).
     */
    protected static function booted(): void
    {
        static::saved(function (User $user) {
            if ($user->wasChanged('password') || ($user->wasChanged('is_active') && ! $user->is_active)) {
                $user->tokens()->delete();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'anonymized_at' => 'datetime',
        ];
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function deliveryPartner(): HasOne
    {
        return $this->hasOne(DeliveryPartner::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function mobileDevices(): HasMany
    {
        return $this->hasMany(MobileDevice::class);
    }

    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favorites')->withTimestamps();
    }

    /**
     * Lien de réinitialisation : page du site (le même lien sert depuis l'application mobile).
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetLink($token));
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(self::STAFF_ROLES);
    }
}
