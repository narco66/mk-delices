<?php

namespace App\Domain\Orders;

use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Accès invité au suivi : jeton secret haché, à durée limitée. Jamais de recherche par numéro seul.
 * Les erreurs sont indistinctes (404) pour ne rien révéler (T-017).
 */
class OrderTracking
{
    public function __construct(private readonly Settings $settings) {}

    /** Génère un nouveau jeton (renvoyé une seule fois au client) et l'attache à la commande (non sauvegardée). */
    public function issue(Order $order): string
    {
        $token = Str::random(40);
        $order->tracking_token_hash = hash('sha256', $token);
        $order->tracking_token_expires_at = now()->addDays((int) $this->settings->get('ordering.tracking_token_ttl_days', 30));

        return $token;
    }

    public function resolve(string $reference, ?string $token): Order
    {
        $order = Order::query()->where('reference', strtoupper($reference))->first();
        if (! $order || ! $token || ! $order->tracking_token_hash
            || ! hash_equals($order->tracking_token_hash, hash('sha256', $token))
            || $order->tracking_token_expires_at === null || $order->tracking_token_expires_at->isPast()) {
            throw new DomainException('order_not_found', 'Commande introuvable ou lien de suivi expiré.', 404);
        }

        return $order;
    }
}
