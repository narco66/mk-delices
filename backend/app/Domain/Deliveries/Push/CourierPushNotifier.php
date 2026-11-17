<?php

namespace App\Domain\Deliveries\Push;

use App\Models\DeliveryAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Notifications du navigateur aux livreurs (décision MK Délices du 17/11/2026) : prévenir d'une nouvelle mission
 * même application fermée. Complément de l'actualisation automatique de la liste, jamais une condition du métier.
 */
class CourierPushNotifier
{
    public function __construct(private readonly PushTransport $transport) {}

    public static function enabled(): bool
    {
        return filled(config('mkdelices.push.vapid_public_key')) && filled(config('mkdelices.push.vapid_private_key'));
    }

    /** Adresse d'abonnement acceptée : HTTPS vers un service de notification connu (pas de requête vers un hôte arbitraire). */
    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = mb_strtolower($parts['host']);
        foreach ((array) config('mkdelices.push.allowed_hosts') as $pattern) {
            if (Str::is(mb_strtolower($pattern), $host)) {
                return true;
            }
        }

        return false;
    }

    public function newMission(DeliveryAssignment $mission): void
    {
        $mission->loadMissing(['order', 'partner.user']);
        $order = $mission->order;
        $address = $order->address_snapshot ?? [];
        $where = (string) ($address['district'] ?? $address['zone_name'] ?? '');
        $this->sendTo($mission->partner?->user, [
            'title' => 'Nouvelle mission MK Délices',
            // Données minimales (pas de nom ni de téléphone du client dans la notification).
            'body' => trim("Commande {$order->reference}".($where !== '' ? " · {$where}" : '')).'. Ouvrez pour accepter.',
            'url' => "/livreur/missions/{$mission->id}",
            'tag' => "mission-{$mission->id}",
        ], 3600);
    }

    /** @param array<string, mixed> $payload */
    public function sendTo(?User $user, array $payload, int $ttlSeconds = 3600): int
    {
        if (! $user || ! $user->is_active || ! self::enabled()) {
            return 0;
        }
        $subscriptions = $user->pushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }
        $results = $this->transport->send($subscriptions, $payload, $ttlSeconds);

        $sent = 0;
        foreach ($subscriptions as $sub) {
            $result = $results[$sub->endpoint] ?? 'failed';
            if ($result === 'sent') {
                $sent++;
                $sub->forceFill(['last_success_at' => now()])->save();
            } elseif ($result === 'expired') {
                // Abonnement révoqué ou expiré (404/410) : supprimé pour ne plus être tenté.
                $sub->delete();
            } else {
                Log::warning('Notification livreur non transmise', ['subscription' => $sub->id]);
            }
        }

        return $sent;
    }
}
