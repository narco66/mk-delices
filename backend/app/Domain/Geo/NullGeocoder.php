<?php

namespace App\Domain\Geo;

/** Aucun fournisseur configuré : ni recherche d'adresse ni quartier suggéré (la saisie manuelle suffit). */
class NullGeocoder implements Geocoder
{
    public function enabled(): bool
    {
        return false;
    }

    public function reverse(GeoPoint $point): ?GeocodedPlace
    {
        return null;
    }

    public function search(string $query, ?GeoPoint $near = null): array
    {
        return [];
    }
}
