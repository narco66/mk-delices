<?php

namespace App\Domain\Deliveries\Push;

use App\Models\PushSubscription;

/** Envoi effectif des notifications du navigateur (remplacé par un faux en test). */
interface PushTransport
{
    /**
     * @param  iterable<PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload  données affichées par le service worker (titre, texte, lien)
     * @return array<string, 'sent'|'expired'|'failed'> résultat par adresse d'abonnement
     */
    public function send(iterable $subscriptions, array $payload, int $ttlSeconds): array;
}
