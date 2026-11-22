<?php

namespace App\Domain\Customers\Push;

use App\Models\MobileDevice;

/** Envoi effectif des notifications push de l'application mobile (remplacé par un faux en test). */
interface MobilePushTransport
{
    /** Transport utilisable (identifiants du prestataire présents). */
    public function isConfigured(): bool;

    /**
     * @param  iterable<MobileDevice>  $devices
     * @param  array{title:string, body:string, data:array<string,string>}  $message
     * @return array<string, 'sent'|'expired'|'failed'> résultat par identifiant d'appareil
     */
    public function send(iterable $devices, array $message): array;
}
