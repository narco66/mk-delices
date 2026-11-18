<?php

namespace App\Domain\Geo;

/** Résultat de géocodage : une SUGGESTION, toujours modifiable et confirmée par le client. */
final readonly class GeocodedPlace
{
    public function __construct(
        public string $label,
        public ?GeoPoint $point = null,
        public ?string $district = null,
        public ?string $street = null,
    ) {}

    public function toArray(): array
    {
        return ['label' => $this->label, 'lat' => $this->point?->lat, 'lng' => $this->point?->lng, 'district' => $this->district, 'street' => $this->street];
    }
}
