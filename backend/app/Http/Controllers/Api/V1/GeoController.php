<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Geo\Geocoder;
use App\Domain\Geo\GeocoderUnavailable;
use App\Domain\Geo\GeoPoint;
use App\Domain\Geo\ZoneLocator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Géolocalisation du client (site et application mobile). Aucune position n'est enregistrée ici : la position
 * n'est conservée qu'avec la commande ou l'adresse que le client valide.
 */
class GeoController extends Controller
{
    /** Zone desservie (calculée par le serveur) et adresse suggérée pour une position. */
    public function resolve(Request $request, ZoneLocator $locator, Geocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'zone_id' => ['nullable', 'uuid'],
        ]);
        $point = GeoPoint::of($data['lat'], $data['lng']);
        $located = $locator->locate($point, $data['zone_id'] ?? null);
        $zone = $located['zone'];

        [$suggestion, $status] = [null, $geocoder->enabled() ? 'ok' : 'disabled'];
        if ($geocoder->enabled()) {
            try {
                $suggestion = $geocoder->reverse($point)?->toArray();
            } catch (GeocoderUnavailable) {
                $status = 'unavailable';
            }
        }

        return response()->json(['data' => [
            'point' => $point->toArray(),
            'zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name, 'delivery_fee_xof' => $zone->delivery_fee_xof, 'min_order_xof' => $zone->min_order_xof] : null,
            'zone_source' => $located['source'],
            'zones_mapped' => $located['mapped'],
            'in_service_area' => $located['in_service_area'],
            // Livrable : dans une zone dessinée, ou zones pas encore dessinées (choix manuel de la zone).
            'deliverable' => $zone !== null || ! $located['mapped'],
            'suggestion' => $suggestion,
            'geocoding' => $status,
        ]]);
    }

    /** Recherche d'une adresse ou d'un lieu (suggestions limitées à la région desservie). */
    public function search(Request $request, Geocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:120'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);
        if (! $geocoder->enabled()) {
            return response()->json(['data' => [], 'meta' => ['geocoding' => 'disabled']]);
        }
        $near = isset($data['lat'], $data['lng']) ? new GeoPoint((float) $data['lat'], (float) $data['lng']) : null;
        try {
            $places = $geocoder->search($data['q'], $near?->inServiceRegion() ? $near : null);
        } catch (GeocoderUnavailable) {
            return response()->json(['data' => [], 'meta' => ['geocoding' => 'unavailable']]);
        }

        return response()->json(['data' => array_map(fn ($p) => $p->toArray(), $places), 'meta' => ['geocoding' => 'ok']]);
    }
}
