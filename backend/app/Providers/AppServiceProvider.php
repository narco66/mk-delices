<?php

namespace App\Providers;

use App\Domain\Customers\Push\FcmTransport;
use App\Domain\Customers\Push\MobilePushTransport;
use App\Domain\Deliveries\Push\PushTransport;
use App\Domain\Deliveries\Push\WebPushTransport;
use App\Domain\Geo\Geocoder;
use App\Domain\Geo\MapTilerGeocoder;
use App\Domain\Geo\NullGeocoder;
use App\Domain\Shared\Settings;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->bind(PushTransport::class, WebPushTransport::class);
        $this->app->bind(MobilePushTransport::class, FcmTransport::class);
        // Fournisseur de géocodage choisi par configuration (DEC-GEO-01) ; inactif sans clé.
        $this->app->bind(Geocoder::class, fn () => config('mkdelices.maps.geocoder') === 'maptiler' && filled(config('mkdelices.maps.geocoder_key'))
            ? new MapTilerGeocoder : new NullGeocoder);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Identifiants UUID : un format invalide renvoie 404 au lieu d'une erreur PostgreSQL.
        foreach (['id', 'order', 'payment', 'refund', 'assignment', 'review', 'image', 'invoice'] as $param) {
            Route::pattern($param, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }
        Route::pattern('ref', 'MKD-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}');

        // L'administrateur dispose de toutes les permissions (deny-by-default pour les autres rôles).
        Gate::before(fn (User $user) => $user->hasRole(User::ROLE_ADMIN) && $user->is_active ? true : null);

        // Limitation de débit (SEC-007).
        $key = fn (Request $r) => $r->user()?->id ?: $r->ip();
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($key($r)));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(mb_strtolower((string) ($r->input('login') ?? $r->input('email') ?? $r->input('phone'))).'|'.$r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
        // Mot de passe oublié : peu de demandes par adresse et par IP (pas d'envoi massif d'emails).
        RateLimiter::for('password-reset', fn (Request $r) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perHour(20)->by($r->ip()),
        ]);
        RateLimiter::for('cart', fn (Request $r) => Limit::perMinute(60)->by($key($r)));
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(20)->by($key($r)));
        // Géolocalisation : chaque appel peut coûter une requête au fournisseur cartographique.
        RateLimiter::for('geo', fn (Request $r) => Limit::perMinute(30)->by($key($r)));
        RateLimiter::for('tracking', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));

        // Alertes d'exploitation (audit E9) : remontées au canal d'alerte (LOG_STACK=daily,slack en production).
        Queue::failing(fn (JobFailed $event) => Log::error('queue.job_failed', [
            'job' => $event->job->resolveName(),
            'error' => mb_substr($event->exception->getMessage(), 0, 300),
        ]));
        Event::listen(QueueBusy::class, fn (QueueBusy $event) => Log::critical('queue.busy', [
            'queue' => $event->queue,
            'size' => $event->size,
        ]));
    }
}
