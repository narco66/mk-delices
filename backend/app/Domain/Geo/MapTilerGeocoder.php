<?php

namespace App\Domain\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Géocodage MapTiler (données OpenStreetMap), appelé uniquement par le serveur : la clé reste secrète.
 * Réponse attendue : GeoJSON FeatureCollection (place_name, text, center [lng, lat], context[]).
 * Format à revérifier sur un compte réel avant la mise en production (docs/audit-geolocalisation/07-GUIDE-CONFIGURATION.md).
 */
class MapTilerGeocoder implements Geocoder
{
    private const BASE = 'https://api.maptiler.com/geocoding/';

    /** Niveaux du contexte assimilés à un « quartier » à Dakar (du plus fin au plus large). */
    private const DISTRICT_LEVELS = ['neighbourhood', 'neighborhood', 'subcity', 'municipal_district', 'locality', 'municipality'];

    public function enabled(): bool
    {
        return filled(config('mkdelices.maps.geocoder_key'));
    }

    public function reverse(GeoPoint $point): ?GeocodedPlace
    {
        $features = $this->get("{$point->lng},{$point->lat}.json", ['limit' => 1]);
        $place = $features === [] ? null : $this->toPlace($features[0]);

        return $place ? new GeocodedPlace($place->label, $point, $place->district, $place->street) : null;
    }

    public function search(string $query, ?GeoPoint $near = null): array
    {
        $b = config('mkdelices.maps.bounds');
        $params = ['limit' => 5, 'country' => 'sn', 'bbox' => "{$b['min_lng']},{$b['min_lat']},{$b['max_lng']},{$b['max_lat']}"];
        if ($near) {
            $params['proximity'] = "{$near->lng},{$near->lat}";
        }
        $places = [];
        foreach ($this->get(rawurlencode(mb_substr(trim($query), 0, 120)).'.json', $params) as $feature) {
            $place = $this->toPlace($feature);
            if ($place?->point && $place->point->inServiceRegion()) {
                $places[] = $place;
            }
        }

        return $places;
    }

    /** @return list<array<string, mixed>> */
    private function get(string $path, array $params): array
    {
        $params += ['key' => config('mkdelices.maps.geocoder_key'), 'language' => 'fr'];
        $ttl = (int) config('mkdelices.maps.geocoder_cache_seconds');
        $fetch = function () use ($path, $params) {
            try {
                $response = Http::timeout((int) config('mkdelices.maps.geocoder_timeout', 4))->acceptJson()
                    ->retry(1, 300, throw: false)->get(self::BASE.$path, $params);
            } catch (ConnectionException $e) {
                Log::warning('Géocodage indisponible', ['provider' => 'maptiler', 'error' => $e->getMessage()]);
                throw new GeocoderUnavailable('timeout', 0, $e);
            }
            if (! $response->successful()) {
                // Clé et adresse demandée jamais journalisées (données personnelles, secret).
                Log::warning('Géocodage refusé', ['provider' => 'maptiler', 'status' => $response->status()]);
                throw new GeocoderUnavailable("http_{$response->status()}");
            }

            return array_values(array_filter((array) $response->json('features', []), 'is_array'));
        };

        return $ttl > 0 ? Cache::remember('geo:maptiler:'.sha1($path.json_encode($params)), $ttl, $fetch) : $fetch();
    }

    private function toPlace(array $feature): ?GeocodedPlace
    {
        $label = (string) ($feature['place_name'] ?? $feature['text'] ?? '');
        if ($label === '') {
            return null;
        }
        $center = $feature['center'] ?? $feature['geometry']['coordinates'] ?? null;
        $point = is_array($center) && is_numeric($center[0] ?? null) && is_numeric($center[1] ?? null)
            ? new GeoPoint(round((float) $center[1], 6), round((float) $center[0], 6)) : null;

        $district = null;
        $levels = array_merge([$feature], (array) ($feature['context'] ?? []));
        foreach (self::DISTRICT_LEVELS as $level) {
            foreach ($levels as $item) {
                $types = array_merge((array) ($item['place_type'] ?? []), [strtok((string) ($item['id'] ?? ''), '.')]);
                if (in_array($level, $types, true) && filled($item['text'] ?? null)) {
                    $district = (string) $item['text'];
                    break 2;
                }
            }
        }
        $types = (array) ($feature['place_type'] ?? []);
        $street = array_intersect($types, ['street', 'address', 'road']) !== [] ? (string) ($feature['text'] ?? '') : null;

        return new GeocodedPlace(mb_substr($label, 0, 255), $point, $district ? mb_substr($district, 0, 120) : null, $street ? mb_substr($street, 0, 200) : null);
    }
}
