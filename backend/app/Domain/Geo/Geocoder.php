<?php

namespace App\Domain\Geo;

/**
 * Géocodage interchangeable (DEC-GEO-01) : remplacer le fournisseur = écrire une nouvelle implémentation.
 * Toute erreur du fournisseur lève GeocoderUnavailable ; l'appelant se replie sur la saisie manuelle.
 */
interface Geocoder
{
    public function enabled(): bool;

    /** Quartier et voie suggérés pour une position (null si rien de pertinent). */
    public function reverse(GeoPoint $point): ?GeocodedPlace;

    /** @return list<GeocodedPlace> lieux de la région desservie correspondant au texte */
    public function search(string $query, ?GeoPoint $near = null): array;
}
