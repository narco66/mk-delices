<?php

namespace App\Domain\Geo;

use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Models\DeliveryZone;

/**
 * Détermine la zone de livraison à partir de la position (source de vérité côté serveur, DEC-GEO-02).
 *
 * Règles (docs/audit-geolocalisation/03-ARCHITECTURE-CIBLE.md) :
 * - périmètre desservi défini (paramètre delivery.service_area, ex. ville de Dakar) et position HORS du
 *   périmètre : refus « hors zone », quelle que soit la zone choisie ;
 * - aucune zone active n'a de contour : mode « déclaré » (le client choisit sa zone tarifaire) ;
 * - position dans un ou plusieurs contours de zone : la zone de plus petite « position » l'emporte
 *   (chevauchement et frontière) ; la zone déclarée par le client est ignorée ;
 * - position hors de tous les contours de zone : refus, sauf si la zone déclarée n'a pas encore de contour
 *   (elle reste alors acceptée comme déclarée, à vérifier par le commercial).
 */
class ZoneLocator
{
    public const SOURCE_POSITION = 'position';

    public const SOURCE_DECLARED = 'declared';

    public const SERVICE_AREA_SETTING = 'delivery.service_area';

    public function __construct(private readonly Settings $settings) {}

    /** @return array{type: string, coordinates: array}|null */
    public function serviceArea(): ?array
    {
        $area = $this->settings->get(self::SERVICE_AREA_SETTING);

        return is_array($area) && isset($area['type'], $area['coordinates']) ? $area : null;
    }

    /** @return array{zone: ?DeliveryZone, source: string, mapped: bool, in_service_area: bool} */
    public function locate(?GeoPoint $point, ?string $declaredZoneId): array
    {
        $zones = DeliveryZone::query()->where('active', true)->orderBy('position')->orderBy('name')->get();
        $serviceArea = $this->serviceArea();
        $mapped = $serviceArea !== null || $zones->contains(fn (DeliveryZone $z) => $z->area !== null);
        $declared = $declaredZoneId ? $zones->firstWhere('id', $declaredZoneId) : null;

        if ($point === null || ! $mapped) {
            return ['zone' => $declared, 'source' => self::SOURCE_DECLARED, 'mapped' => $mapped, 'in_service_area' => true];
        }
        if ($serviceArea !== null && ! ZoneArea::contains($serviceArea, $point)) {
            return ['zone' => null, 'source' => self::SOURCE_POSITION, 'mapped' => true, 'in_service_area' => false];
        }
        $match = $zones->first(fn (DeliveryZone $z) => $z->area !== null && ZoneArea::contains($z->area, $point));
        if ($match) {
            return ['zone' => $match, 'source' => self::SOURCE_POSITION, 'mapped' => true, 'in_service_area' => true];
        }
        // Dans le périmètre (ou sans périmètre) mais hors des contours de zone : zone déclarée si elle n'a pas de contour.
        if ($declared && $declared->area === null) {
            return ['zone' => $declared, 'source' => self::SOURCE_DECLARED, 'mapped' => true, 'in_service_area' => true];
        }
        // Périmètre défini, aucune zone dessinée et pas encore de zone choisie : livrable, le client choisit sa zone.
        if ($declared === null && $serviceArea !== null && ! $zones->contains(fn (DeliveryZone $z) => $z->area !== null)) {
            return ['zone' => null, 'source' => self::SOURCE_DECLARED, 'mapped' => false, 'in_service_area' => true];
        }

        return ['zone' => null, 'source' => self::SOURCE_POSITION, 'mapped' => true, 'in_service_area' => true];
    }

    /** Variante stricte pour la commande : refuse une position hors du périmètre ou des zones dessinées. */
    public function locateOrFail(?GeoPoint $point, ?string $declaredZoneId): array
    {
        $result = $this->locate($point, $declaredZoneId);
        if ($point !== null && $result['zone'] === null && $result['mapped']) {
            throw DomainException::unprocessable('out_of_zone', 'Cette adresse n\'est pas dans nos zones de livraison. Contactez-nous sur WhatsApp pour une livraison sur devis.');
        }

        return $result;
    }
}
