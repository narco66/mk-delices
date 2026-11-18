<?php

namespace Tests\Feature;

use App\Domain\Geo\GeoPoint;
use App\Domain\Geo\ZoneArea;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Géolocalisation (docs/audit-geolocalisation, matrice de 06-RAPPORT-DE-TESTS.md). */
class GeolocationTest extends TestCase
{
    /** Point de test (Dakar) et carrés de ±0,01° autour. */
    private const LAT = 14.70;

    private const LNG = -17.45;

    private function square(float $lat, float $lng, float $d = 0.01): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$lng - $d, $lat - $d], [$lng + $d, $lat - $d], [$lng + $d, $lat + $d], [$lng - $d, $lat + $d], [$lng - $d, $lat - $d]]]];
    }

    private function at(float $lat = self::LAT, float $lng = self::LNG, array $extra = []): array
    {
        return ['delivery' => ['lat' => $lat, 'lng' => $lng, 'location_source' => 'gps', 'accuracy_m' => 15] + $extra];
    }

    // ---- GEO-ZONE : contours ---------------------------------------------------------------------

    public function test_point_in_polygon_handles_holes_and_multipolygons(): void
    {
        $p = new GeoPoint(self::LAT, self::LNG);
        $this->assertTrue(ZoneArea::contains($this->square(self::LAT, self::LNG), $p));
        $this->assertFalse(ZoneArea::contains($this->square(self::LAT + 0.05, self::LNG), $p));

        $withHole = $this->square(self::LAT, self::LNG, 0.02);
        $withHole['coordinates'][] = $this->square(self::LAT, self::LNG, 0.005)['coordinates'][0];
        $this->assertFalse(ZoneArea::contains($withHole, $p), 'point dans un trou');

        $multi = ['type' => 'MultiPolygon', 'coordinates' => [$this->square(self::LAT + 0.05, self::LNG)['coordinates'], $this->square(self::LAT, self::LNG)['coordinates']]];
        $this->assertTrue(ZoneArea::contains(ZoneArea::normalize($multi), $p));
    }

    public function test_area_validation_closes_rings_and_rejects_invalid_shapes(): void
    {
        $open = ['type' => 'Polygon', 'coordinates' => [[[-17.46, 14.69], [-17.44, 14.69], [-17.44, 14.71]]]];
        $normalized = ZoneArea::normalize($open);
        $this->assertCount(4, $normalized['coordinates'][0]);
        $this->assertSame($normalized['coordinates'][0][0], $normalized['coordinates'][0][3]);

        $this->assertIsString(ZoneArea::normalize(['type' => 'Point', 'coordinates' => [-17.4, 14.7]]));
        $this->assertIsString(ZoneArea::normalize(['type' => 'Polygon', 'coordinates' => [[[-17.46, 14.69], [-17.44, 14.69]]]]));
        $this->assertIsString(ZoneArea::normalize($this->square(48.85, 2.35)), 'hors de Dakar');
    }

    public function test_zone_manager_saves_area_and_invalid_area_is_rejected(): void
    {
        $manager = $this->staff(User::ROLE_ADMIN);
        $zone = $this->zone();
        $body = ['name' => $zone->name, 'delivery_fee_xof' => 1500, 'partner_fee_xof' => 1000];

        $this->actingAs($manager)->putJson("/api/v1/admin/delivery/zones/{$zone->id}", $body + ['area' => $this->square(self::LAT, self::LNG)])->assertOk()
            ->assertJsonPath('data.area.type', 'Polygon');
        $this->putJson("/api/v1/admin/delivery/zones/{$zone->id}", $body + ['area' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0]]]]])
            ->assertStatus(422)->assertJsonValidationErrors('area', 'error.details');
        // Effacer le contour : la zone redevient « déclarée ».
        $this->putJson("/api/v1/admin/delivery/zones/{$zone->id}", $body + ['area' => null])->assertOk()->assertJsonPath('data.area', null);
        // Sans le contour dans la requête, il n'est pas touché.
        $zone->update(['area' => $this->square(self::LAT, self::LNG)]);
        $this->putJson("/api/v1/admin/delivery/zones/{$zone->id}", $body)->assertOk()->assertJsonPath('data.area.type', 'Polygon');

        $this->actingAs($this->staff(User::ROLE_LIVREUR))->putJson("/api/v1/admin/delivery/zones/{$zone->id}", $body + ['area' => null])->assertForbidden();
    }

    // ---- GEO-PRICE : zone et frais déterminés par le serveur -------------------------------------

    public function test_position_determines_zone_and_fee_whatever_the_declared_zone(): void
    {
        $near = $this->zone(['delivery_fee_xof' => 1500, 'area' => $this->square(self::LAT, self::LNG), 'position' => 1]);
        $declared = $this->zone(['delivery_fee_xof' => 500, 'area' => $this->square(self::LAT + 0.05, self::LNG), 'position' => 2]);

        $ref = $this->placeOrder($this->variant(), $declared, 2, $this->at())->assertCreated()->json('data.reference');
        $order = Order::query()->where('reference', $ref)->firstOrFail();

        $this->assertSame($near->id, $order->zone_id, 'zone calculée, pas celle déclarée (moins chère)');
        $this->assertSame(1500, $order->delivery_fee_xof);
        $this->assertSame('position', $order->address_snapshot['zone_source']);
        $this->assertSame(self::LAT, $order->address_snapshot['lat']);
        $this->assertSame('gps', $order->address_snapshot['location_source']);
        $this->assertSame(15, $order->address_snapshot['accuracy_m']);
    }

    public function test_position_outside_every_drawn_zone_is_refused(): void
    {
        $zone = $this->zone(['area' => $this->square(self::LAT, self::LNG)]);
        $this->placeOrder($this->variant(), $zone, 2, $this->at(self::LAT + 0.2))->assertStatus(422)->assertJsonPath('error.code', 'out_of_zone');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_position_outside_dakar_region_is_refused(): void
    {
        $this->placeOrder($this->variant(), $this->zone(), 2, $this->at(14.70, -16.0))->assertStatus(422);
        $this->postJson('/api/v1/geo/resolve', ['lat' => 48.85, 'lng' => 2.35])->assertStatus(422)->assertJsonPath('error.code', 'location_out_of_region');
    }

    public function test_without_any_drawn_zone_the_declared_zone_is_kept(): void
    {
        $zone = $this->zone(['delivery_fee_xof' => 2000]);
        [$order] = $this->createOrder(null, $zone, $this->at());
        $this->assertSame($zone->id, $order->zone_id);
        $this->assertSame('declared', $order->address_snapshot['zone_source']);
        $this->assertSame(self::LNG, $order->address_snapshot['lng'], 'position conservée pour le livreur');
    }

    public function test_manual_address_without_position_keeps_declared_zone(): void
    {
        $this->zone(['area' => $this->square(self::LAT, self::LNG)]);
        $declared = $this->zone(['delivery_fee_xof' => 3000, 'area' => $this->square(self::LAT + 0.05, self::LNG)]);
        [$order] = $this->createOrder(null, $declared);
        $this->assertSame($declared->id, $order->zone_id);
        $this->assertSame('declared', $order->address_snapshot['zone_source']);
        $this->assertNull($order->address_snapshot['lat']);
    }

    public function test_declared_zone_not_yet_drawn_is_accepted_when_point_is_outside_drawn_zones(): void
    {
        $this->zone(['area' => $this->square(self::LAT, self::LNG)]);
        $undrawn = $this->zone();
        [$order] = $this->createOrder(null, $undrawn, $this->at(self::LAT + 0.2));
        $this->assertSame($undrawn->id, $order->zone_id);
        $this->assertSame('declared', $order->address_snapshot['zone_source']);
    }

    public function test_overlapping_zones_lowest_position_wins(): void
    {
        $second = $this->zone(['delivery_fee_xof' => 1000, 'area' => $this->square(self::LAT, self::LNG, 0.02), 'position' => 5]);
        $first = $this->zone(['delivery_fee_xof' => 2500, 'area' => $this->square(self::LAT, self::LNG), 'position' => 1]);
        [$order] = $this->createOrder(null, $second, $this->at());
        $this->assertSame($first->id, $order->zone_id);
    }

    public function test_changed_total_is_reported_instead_of_charged(): void
    {
        $zone = $this->zone(['delivery_fee_xof' => 1500]);
        $variant = $this->variant();
        $this->placeOrder($variant, $zone, 2, ['expected_total_xof' => 6000])->assertStatus(409)
            ->assertJsonPath('error.code', 'total_changed')->assertJsonPath('error.details.total_xof', 6500);
        $this->placeOrder($variant, $zone, 2, ['expected_total_xof' => 6500])->assertCreated();
    }

    public function test_quote_with_position_uses_computed_zone_or_reports_out_of_zone(): void
    {
        $zone = $this->zone(['delivery_fee_xof' => 2000, 'area' => $this->square(self::LAT, self::LNG)]);
        $cart = $this->cartWith($this->variant());
        $this->postJson('/api/v1/checkout/quote', ['lat' => self::LAT, 'lng' => self::LNG], $this->cartHeaders(...$cart))->assertOk()
            ->assertJsonPath('data.zone.id', $zone->id)->assertJsonPath('data.delivery_fee_xof', 2000)->assertJsonPath('data.zone_source', 'position');
        $out = $this->postJson('/api/v1/checkout/quote', ['lat' => self::LAT + 0.2, 'lng' => self::LNG], $this->cartHeaders(...$cart))->assertOk();
        $this->assertFalse($out->json('data.valid'));
        $this->assertContains('out_of_zone', array_column($out->json('data.issues'), 'code'));
    }

    // ---- GEO-LOC : résolution et recherche (fournisseur) -----------------------------------------

    public function test_resolve_returns_zone_and_works_without_geocoder(): void
    {
        $zone = $this->zone(['area' => $this->square(self::LAT, self::LNG)]);
        $this->postJson('/api/v1/geo/resolve', ['lat' => self::LAT, 'lng' => self::LNG])->assertOk()
            ->assertJsonPath('data.zone.id', $zone->id)->assertJsonPath('data.deliverable', true)
            ->assertJsonPath('data.geocoding', 'disabled')->assertJsonPath('data.suggestion', null);
        $this->postJson('/api/v1/geo/resolve', ['lat' => self::LAT + 0.2, 'lng' => self::LNG])->assertOk()
            ->assertJsonPath('data.zone', null)->assertJsonPath('data.deliverable', false);
    }

    public function test_resolve_suggests_district_from_provider_and_degrades_on_failure(): void
    {
        config(['mkdelices.maps.geocoder' => 'maptiler', 'mkdelices.maps.geocoder_key' => 'test-key']);
        Http::fake(['api.maptiler.com/*' => Http::sequence()->push(['type' => 'FeatureCollection', 'features' => [[
            'place_name' => 'Rue 10, Sicap Liberté, Dakar, Sénégal', 'text' => 'Rue 10', 'place_type' => ['street'],
            'center' => [self::LNG, self::LAT],
            'context' => [['id' => 'neighbourhood.1', 'text' => 'Sicap Liberté'], ['id' => 'municipality.2', 'text' => 'Dakar']],
        ]]])->push('quota', 429)]);
        $this->postJson('/api/v1/geo/resolve', ['lat' => self::LAT, 'lng' => self::LNG])->assertOk()
            ->assertJsonPath('data.geocoding', 'ok')->assertJsonPath('data.suggestion.district', 'Sicap Liberté')->assertJsonPath('data.suggestion.street', 'Rue 10');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'key=test-key') && str_contains($r->url(), '-17.45,14.7.json'));

        // Deuxième appel : quota dépassé chez le fournisseur.
        $this->postJson('/api/v1/geo/resolve', ['lat' => self::LAT, 'lng' => self::LNG])->assertOk()
            ->assertJsonPath('data.geocoding', 'unavailable')->assertJsonPath('data.suggestion', null);
    }

    public function test_district_is_read_from_real_maptiler_format_in_dakar(): void
    {
        config(['mkdelices.maps.geocoder' => 'maptiler', 'mkdelices.maps.geocoder_key' => 'test-key']);
        // Réponses relevées sur l'API MapTiler le 18/11/2026 (contexte « place » / « municipality »).
        $address = fn (string $street, array $context) => ['type' => 'FeatureCollection', 'features' => [[
            'place_name' => "{$street}, Dakar, Sénégal", 'text' => $street, 'place_type' => ['address'], 'center' => [self::LNG, self::LAT], 'context' => $context,
        ]]];
        Http::fake(['api.maptiler.com/*' => Http::sequence()
            // Une voie rangée au niveau « place » n'est jamais un quartier ; commune saisie en minuscules.
            ->push($address('Voie de Dégagement Nord', [['id' => 'place.1', 'text' => 'Rue PA-18-33 454'], ['id' => 'municipality.2', 'text' => 'parcelles assainies']]))
            ->push($address('Rue Lamine Barry', [['id' => 'place.3', 'text' => 'Medina'], ['id' => 'municipality.4', 'text' => 'Commune de Médina']]))
            ->push($address('Avenue Pompidou', [['id' => 'municipality.5', 'text' => 'Commune de Dakar-Plateau']]))
            ->push(['features' => [['place_name' => 'Sacré Coeur 3, Dakar', 'text' => 'Sacré Coeur 3', 'place_type' => ['place'], 'center' => [-17.47, 14.72],
                'context' => [['id' => 'municipality.6', 'text' => 'Commune de Sicap-Liberté']]]]])]);

        foreach (['Parcelles Assainies', 'Medina', 'Dakar-Plateau'] as $expected) {
            $this->postJson('/api/v1/geo/resolve', ['lat' => self::LAT, 'lng' => self::LNG])->assertOk()->assertJsonPath('data.suggestion.district', $expected);
        }
        $this->getJson('/api/v1/geo/search?q=Sacre+Coeur+3')->assertOk()->assertJsonPath('data.0.district', 'Sacré Coeur 3');
    }

    public function test_search_is_limited_to_service_region(): void
    {
        $this->getJson('/api/v1/geo/search?q=Sacre+Coeur')->assertOk()->assertJsonPath('meta.geocoding', 'disabled');

        config(['mkdelices.maps.geocoder' => 'maptiler', 'mkdelices.maps.geocoder_key' => 'test-key']);
        Http::fake(['api.maptiler.com/*' => Http::response(['features' => [
            ['place_name' => 'Sacré-Cœur 3, Dakar', 'text' => 'Sacré-Cœur 3', 'place_type' => ['neighbourhood'], 'center' => [-17.47, 14.72]],
            ['place_name' => 'Sacré-Cœur, Paris', 'text' => 'Sacré-Cœur', 'place_type' => ['poi'], 'center' => [2.34, 48.88]],
        ]])]);
        $this->getJson('/api/v1/geo/search?q=Sacre+Coeur')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.district', 'Sacré-Cœur 3')->assertJsonPath('data.0.lat', 14.72);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'country=sn') && str_contains($r->url(), 'bbox='));
    }

    public function test_public_config_exposes_map_settings_without_secret(): void
    {
        config(['mkdelices.maps.tiles_url' => 'https://tiles.example/{z}/{x}/{y}.png?key=public', 'mkdelices.maps.geocoder_key' => 'server-secret', 'mkdelices.maps.geocoder' => 'maptiler']);
        $res = $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.maps.geocoding', true);
        $this->assertStringNotContainsString('server-secret', $res->getContent());
        $this->assertSame('https://tiles.example/{z}/{x}/{y}.png?key=public', $res->json('data.maps.tiles_url'));
    }

    // ---- Carnet d'adresses et modification par l'équipe -----------------------------------------

    public function test_saved_address_stores_position_and_computed_zone(): void
    {
        $zone = $this->zone(['area' => $this->square(self::LAT, self::LNG)]);
        $other = $this->zone();
        $customer = User::factory()->create(['is_active' => true]);
        Customer::query()->create(['user_id' => $customer->id, 'first_name' => 'Awa', 'phone' => '+221771234567']);
        $this->actingAs($customer)->getJson('/api/v1/account/addresses')->assertOk();

        $this->postJson('/api/v1/account/addresses', ['zone_id' => $other->id, 'district' => 'Liberté 6', 'lat' => self::LAT, 'lng' => self::LNG, 'location_source' => 'map', 'accuracy_m' => 8])
            ->assertCreated()->assertJsonPath('data.zone_id', $zone->id)->assertJsonPath('data.location_source', 'map')->assertJsonPath('data.location_accuracy_m', 8);
        $this->postJson('/api/v1/account/addresses', ['zone_id' => $zone->id, 'district' => 'Loin', 'lat' => self::LAT + 0.2, 'lng' => self::LNG])
            ->assertStatus(422)->assertJsonPath('error.code', 'out_of_zone');
    }

    public function test_staff_edit_keeps_customer_position(): void
    {
        $zone = $this->zone();
        [$order] = $this->createOrder(null, $zone, $this->at());
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->patchJson("/api/v1/admin/orders/{$order->id}/details", [
            'delivery' => ['zone_id' => $zone->id, 'district' => 'Quartier corrigé'],
        ])->assertOk();
        $snapshot = $order->fresh()->address_snapshot;
        $this->assertSame('Quartier corrigé', $snapshot['district']);
        $this->assertSame(self::LAT, $snapshot['lat']);
        $this->assertSame('gps', $snapshot['location_source']);
        $this->assertSame('staff', $snapshot['zone_source']);
    }

    public function test_courier_sees_position_only_after_acceptance(): void
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $order->update(['address_snapshot' => array_merge($order->address_snapshot, ['lat' => self::LAT, 'lng' => self::LNG])]);
        $partner = $this->partner($zone);
        $id = $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->json('data.id');
        $this->actingAs($partner->user)->getJson("/api/v1/courier/missions/{$id}")->assertJsonPath('data.lat', null);
        $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept'])->assertOk()->assertJsonPath('data.lat', self::LAT);
    }

    public function test_stats_by_zone_are_aggregated_without_positions(): void
    {
        $zone = $this->zone(['name' => 'Zone stats', 'delivery_fee_xof' => 1500, 'area' => $this->square(self::LAT, self::LNG)]);
        [$paid, $token] = $this->createOrder(null, $zone, $this->at());
        $this->payOrder($paid, $token);
        $this->createOrder(null, $zone);

        $res = $this->actingAs($this->staff(User::ROLE_ADMIN))->getJson('/api/v1/admin/stats?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk();
        $row = collect($res->json('data.by_zone'))->firstWhere('zone_id', $zone->id);
        $this->assertSame(2, $row['orders']);
        $this->assertSame(1, $row['paid_orders']);
        $this->assertSame(1500, $row['delivery_fees_xof']);
        $this->assertSame(1, $row['positioned']);
        $this->assertSame(1, $row['declared']);
        $this->assertStringNotContainsString((string) self::LNG, json_encode($res->json('data.by_zone')));
    }

    // ---- Corrections de l'audit du 18/11/2026 ----------------------------------------------------

    public function test_single_default_address_is_maintained(): void
    {
        $zone = $this->zone();
        $user = User::factory()->create(['is_active' => true]);
        Customer::query()->create(['user_id' => $user->id, 'first_name' => 'Awa', 'phone' => '+221771234567']);
        $this->actingAs($user);

        $first = $this->postJson('/api/v1/account/addresses', ['zone_id' => $zone->id, 'district' => 'Mermoz'])->assertCreated()
            ->assertJsonPath('data.is_default', true)->json('data.id');
        $second = $this->postJson('/api/v1/account/addresses', ['zone_id' => $zone->id, 'district' => 'Ouakam'])->assertCreated()
            ->assertJsonPath('data.is_default', false)->json('data.id');

        $this->postJson("/api/v1/account/addresses/{$second}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->getJson('/api/v1/account/addresses')->assertOk()->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.is_default', false);

        // Supprimer l'adresse par défaut : la restante prend le relais.
        $this->deleteJson("/api/v1/account/addresses/{$second}")->assertNoContent();
        $this->getJson('/api/v1/account/addresses')->assertJsonPath('data.0.id', $first)->assertJsonPath('data.0.is_default', true);

        // Modifier une adresse en y ajoutant une position ; un autre client ne peut pas la toucher.
        $this->putJson("/api/v1/account/addresses/{$first}", ['zone_id' => $zone->id, 'district' => 'Mermoz', 'lat' => self::LAT, 'lng' => self::LNG, 'location_source' => 'map'])
            ->assertOk()->assertJsonPath('data.lat', self::LAT)->assertJsonPath('data.is_default', true);
        $intruder = User::factory()->create(['is_active' => true]);
        Customer::query()->create(['user_id' => $intruder->id, 'first_name' => 'Intrus', 'phone' => '+221771234568']);
        $this->actingAs($intruder)->postJson("/api/v1/account/addresses/{$first}/default")->assertNotFound();
    }

    public function test_incomplete_coordinates_are_rejected(): void
    {
        $this->placeOrder($this->variant(), $this->zone(), 2, ['delivery' => ['lat' => self::LAT]])->assertStatus(422)
            ->assertJsonValidationErrors('delivery.lng', 'error.details');
    }

    public function test_admin_can_filter_orders_by_zone_and_zone_source(): void
    {
        $mapped = $this->zone(['area' => $this->square(self::LAT, self::LNG)]);
        $other = $this->zone();
        [$byPosition] = $this->createOrder(null, $mapped, $this->at());
        [$declared] = $this->createOrder(null, $other);
        $this->actingAs($this->staff(User::ROLE_ADMIN));

        $this->getJson("/api/v1/admin/orders?zone_id={$mapped->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $byPosition->id);
        $this->getJson('/api/v1/admin/orders?zone_source=declared')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $declared->id);
        $this->getJson('/api/v1/admin/orders?zone_id=none')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/orders?zone_id=abc')->assertStatus(422);
    }

    public function test_position_purge_respects_retention_and_keeps_address_text(): void
    {
        $this->assertSame(90, config('mkdelices.maps.position_retention_days'), 'durée retenue par MK Délices');
        $zone = $this->zone(['area' => $this->square(self::LAT, self::LNG)]);
        [$order] = $this->createOrder(null, $zone, $this->at());
        [$recent] = $this->createOrder(null, $zone, $this->at());
        Order::query()->whereKey($order->id)->update(['status' => 'delivered', 'slot_end_at' => now()->subDays(200)]);
        Order::query()->whereKey($recent->id)->update(['status' => 'delivered', 'slot_end_at' => now()->subDays(30)]);

        config(['mkdelices.maps.position_retention_days' => 0]);
        $this->artisan('mk:purge-delivery-positions')->assertSuccessful();
        $this->assertSame(self::LAT, $order->fresh()->address_snapshot['lat'], 'aucune purge si désactivée');

        config(['mkdelices.maps.position_retention_days' => 90]);
        $this->artisan('mk:purge-delivery-positions', ['--dry-run' => true])->expectsOutputToContain('1 commande(s)')->assertSuccessful();
        $this->assertSame(self::LAT, $order->fresh()->address_snapshot['lat']);
        $this->artisan('mk:purge-delivery-positions')->assertSuccessful();

        $snapshot = $order->fresh()->address_snapshot;
        $this->assertArrayNotHasKey('lat', $snapshot);
        $this->assertArrayNotHasKey('accuracy_m', $snapshot);
        $this->assertArrayHasKey('position_purged_at', $snapshot);
        $this->assertSame($order->address_snapshot['district'], $snapshot['district'], 'adresse textuelle conservée');
        $this->assertSame(self::LAT, $recent->fresh()->address_snapshot['lat'], 'livraison récente (30 j) : position conservée');

        $res = $this->actingAs($this->staff(User::ROLE_ADMIN))->getJson('/api/v1/admin/stats?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk();
        $this->assertSame(2, collect($res->json('data.by_zone'))->firstWhere('zone_id', $zone->id)['positioned'], 'statistiques inchangées');
    }

    // ---- Périmètre desservi : ville de Dakar (décision MK Délices du 18/11/2026) -----------------

    public function test_service_area_of_dakar_refuses_positions_outside_the_city(): void
    {
        $this->artisan('mk:set-service-area', ['file' => base_path('database/data/ville-de-dakar.geojson')])->assertSuccessful();
        $zone = $this->zone(['delivery_fee_xof' => 3000]);
        $mermoz = ['lat' => 14.7106, 'lng' => -17.4747];
        $pikine = ['lat' => 14.7550, 'lng' => -17.3900];

        // Dans Dakar : la zone tarifaire reste celle choisie par le client (à vérifier par le commercial).
        $this->postJson('/api/v1/geo/resolve', $mermoz)->assertOk()->assertJsonPath('data.deliverable', true)->assertJsonPath('data.in_service_area', true);
        [$order] = $this->createOrder(null, $zone, ['delivery' => $mermoz]);
        $this->assertSame($zone->id, $order->zone_id);
        $this->assertSame('declared', $order->address_snapshot['zone_source']);

        // Pikine, Gorée : hors du périmètre, refus en ligne quelle que soit la zone choisie.
        $this->postJson('/api/v1/geo/resolve', $pikine)->assertOk()->assertJsonPath('data.deliverable', false)->assertJsonPath('data.in_service_area', false);
        $this->placeOrder($this->variant(), $zone, 2, ['delivery' => $pikine])->assertStatus(422)->assertJsonPath('error.code', 'out_of_zone');
        $this->placeOrder($this->variant(), $zone, 2, ['delivery' => ['lat' => 14.6670, 'lng' => -17.3985]])->assertStatus(422);
        // Sans position, la saisie manuelle reste possible (contrôle humain).
        $this->placeOrder($this->variant(), $zone, 2)->assertCreated();

        $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.maps.service_area.type', 'Polygon');
    }

    public function test_service_area_is_managed_by_zone_managers_only(): void
    {
        $body = ['area' => $this->square(self::LAT, self::LNG, 0.05)];
        $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))->putJson('/api/v1/admin/delivery/service-area', $body)->assertForbidden();
        $this->getJson('/api/v1/admin/delivery/service-area')->assertOk()->assertJsonPath('data.area', null);

        $this->actingAs($this->staff(User::ROLE_ADMIN))->putJson('/api/v1/admin/delivery/service-area', $body)->assertOk()->assertJsonPath('data.area.type', 'Polygon');
        $this->putJson('/api/v1/admin/delivery/service-area', ['area' => $this->square(48.85, 2.35)])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery.service_area_updated']);

        // La page Paramètres générique ne l'expose ni ne le modifie.
        $this->assertNotContains('delivery.service_area', array_column($this->getJson('/api/v1/admin/settings')->json('data'), 'key'));
        $this->putJson('/api/v1/admin/settings', ['values' => ['delivery.service_area' => null]])->assertStatus(422);

        $this->putJson('/api/v1/admin/delivery/service-area', ['area' => null])->assertOk()->assertJsonPath('data.area', null);
    }

    public function test_zone_without_area_keeps_unchanged_behaviour_for_existing_orders(): void
    {
        $zone = $this->zone(['delivery_fee_xof' => 1500]);
        [$order] = $this->createOrder(null, $zone);
        DeliveryZone::query()->whereKey($zone->id)->update(['delivery_fee_xof' => 9000, 'area' => json_encode($this->square(self::LAT, self::LNG))]);
        $this->assertSame(1500, $order->fresh()->delivery_fee_xof, 'commande passée inchangée');
    }
}
