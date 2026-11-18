<?php

namespace App\Domain\Geo;

use App\Domain\Shared\DomainException;

/** Position WGS84 validée (bornes de la zone de service configurées dans mkdelices.maps.bounds). */
final readonly class GeoPoint
{
    public function __construct(public float $lat, public float $lng) {}

    /** @throws DomainException 422 si la position est hors de la région desservie ou invalide */
    public static function of(mixed $lat, mixed $lng): self
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            throw DomainException::unprocessable('invalid_location', 'Position invalide.');
        }
        $point = new self(round((float) $lat, 6), round((float) $lng, 6));
        if (! $point->inServiceRegion()) {
            throw DomainException::unprocessable('location_out_of_region', 'Cette position est en dehors de la région de Dakar desservie par MK Délices.');
        }

        return $point;
    }

    /** Position facultative : null si l'une des deux coordonnées manque. */
    public static function maybe(mixed $lat, mixed $lng): ?self
    {
        return ($lat === null || $lat === '' || $lng === null || $lng === '') ? null : self::of($lat, $lng);
    }

    public function inServiceRegion(): bool
    {
        $b = config('mkdelices.maps.bounds');

        return $this->lat >= $b['min_lat'] && $this->lat <= $b['max_lat'] && $this->lng >= $b['min_lng'] && $this->lng <= $b['max_lng'];
    }

    /** Distance à vol d'oiseau en mètres (haversine) — jamais utilisée comme distance routière ni pour un prix. */
    public function distanceTo(self $other): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($other->lat - $this->lat);
        $dLng = deg2rad($other->lng - $this->lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->lat)) * cos(deg2rad($other->lat)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** @return array{lat: float, lng: float} */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
