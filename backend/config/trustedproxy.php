<?php

/*
 * Proxys de confiance (audit E1). Par défaut AUCUN : Nginx est exposé directement à Internet, donc
 * X-Forwarded-For, envoyé par le client, ne doit jamais remplacer l'adresse réelle (limitation de
 * débit, journal d'audit). Si un CDN ou un répartiteur est ajouté devant Nginx, lister ses adresses :
 * TRUSTED_PROXIES=10.0.0.10,10.0.0.11
 */
return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
