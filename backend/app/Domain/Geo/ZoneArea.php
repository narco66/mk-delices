<?php

namespace App\Domain\Geo;

/**
 * Contour d'une zone de livraison au format GeoJSON (Polygon ou MultiPolygon, coordonnées [lng, lat], trous
 * acceptés). Test « point dans le polygone » par lancer de rayon : suffisant à l'échelle d'une ville
 * (quelques kilomètres), sans PostGIS.
 */
final class ZoneArea
{
    public const MAX_VERTICES = 500;

    /**
     * Valide et normalise une géométrie reçue du back-office.
     *
     * @return array{type: string, coordinates: array}|string géométrie normalisée, ou message d'erreur
     */
    public static function normalize(mixed $geometry): array|string
    {
        if (! is_array($geometry) || ! in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true) || ! is_array($geometry['coordinates'] ?? null)) {
            return 'Le contour doit être un polygone GeoJSON.';
        }
        $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
        if ($polygons === []) {
            return 'Le contour est vide.';
        }
        $bounds = config('mkdelices.maps.bounds');
        $vertices = 0;
        $out = [];
        foreach ($polygons as $rings) {
            if (! is_array($rings) || $rings === []) {
                return 'Polygone invalide.';
            }
            $outRings = [];
            foreach ($rings as $ring) {
                if (! is_array($ring)) {
                    return 'Polygone invalide.';
                }
                $points = [];
                foreach ($ring as $pt) {
                    if (! is_array($pt) || count($pt) < 2 || ! is_numeric($pt[0]) || ! is_numeric($pt[1])) {
                        return 'Coordonnées invalides dans le contour.';
                    }
                    [$lng, $lat] = [round((float) $pt[0], 6), round((float) $pt[1], 6)];
                    if ($lat < $bounds['min_lat'] || $lat > $bounds['max_lat'] || $lng < $bounds['min_lng'] || $lng > $bounds['max_lng']) {
                        return 'Le contour sort de la région de Dakar.';
                    }
                    $points[] = [$lng, $lat];
                }
                // Anneau fermé (premier point répété à la fin), comme l'exige GeoJSON.
                if ($points !== [] && $points[0] !== end($points)) {
                    $points[] = $points[0];
                }
                if (count(array_unique(array_map(fn ($p) => $p[0].','.$p[1], $points))) < 3) {
                    return 'Un contour doit compter au moins 3 points distincts.';
                }
                $vertices += count($points);
                $outRings[] = $points;
            }
            $out[] = $outRings;
        }
        if ($vertices > self::MAX_VERTICES) {
            return 'Contour trop détaillé (maximum '.self::MAX_VERTICES.' points).';
        }

        return count($out) === 1 ? ['type' => 'Polygon', 'coordinates' => $out[0]] : ['type' => 'MultiPolygon', 'coordinates' => $out];
    }

    /** @param array{type: string, coordinates: array} $geometry géométrie normalisée */
    public static function contains(array $geometry, GeoPoint $point): bool
    {
        $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];
        foreach ($polygons as $rings) {
            if (! self::inRing($rings[0], $point)) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($rings, 1) as $hole) {
                if (self::inRing($hole, $point)) {
                    $inHole = true;
                    break;
                }
            }
            if (! $inHole) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private static function inRing(array $ring, GeoPoint $p): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $p->lat) !== ($yj > $p->lat) && $p->lng < ($xj - $xi) * ($p->lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
