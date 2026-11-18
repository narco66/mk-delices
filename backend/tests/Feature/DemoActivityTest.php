<?php

namespace Tests\Feature;

use App\Domain\Catalog\CatalogImporter;
use App\Domain\Geo\GeoPoint;
use App\Domain\Geo\ZoneArea;
use App\Domain\Geo\ZoneLocator;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\LaunchConfigurationSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Le jeu de démonstration passe par les vrais services : il doit respecter tous les invariants métier. */
class DemoActivityTest extends TestCase
{
    public function test_demo_activity_covers_every_module_and_respects_business_invariants(): void
    {
        $this->seed(DemoDataSeeder::class);
        app(CatalogImporter::class)->import(base_path('../docs/propositions/catalogue-propose.csv'), publish: true);
        $this->seed(LaunchConfigurationSeeder::class);
        // Configuration réelle : livraison limitée à la ville de Dakar.
        $this->artisan('mk:set-service-area', ['file' => base_path('database/data/ville-de-dakar.geojson')])->assertSuccessful();
        $this->seed(DemoActivitySeeder::class);

        $statuses = Order::query()->where('internal_notes', DemoActivitySeeder::MARKER)->pluck('status')->map->value->countBy();
        foreach (['draft', 'pending_confirmation', 'accepted', 'preparing', 'ready', 'assigned', 'picked_up', 'delivered', 'delivery_failed', 'cancelled', 'rejected'] as $status) {
            $this->assertGreaterThan(0, $statuses[$status] ?? 0, "Au moins une commande « {$status} »");
        }
        $this->assertGreaterThan(30, $statuses['delivered']);

        // Invariants : montants, paiements vérifiés, remboursements bornés, une seule mission active.
        $this->assertSame(0, Order::query()->whereRaw('total_xof <> subtotal_xof - discount_xof + delivery_fee_xof')->count());
        $this->assertSame(0, Order::query()->where('payment_status', 'paid')->whereColumn('paid_xof', '<', 'total_xof')->count());
        $this->assertGreaterThan(0, Refund::query()->where('status', 'succeeded')->count());
        $this->assertSame(0, Order::query()->whereColumn('refunded_xof', '>', 'paid_xof')->count());
        $this->assertSame(0, (int) DB::table('delivery_assignments')->selectRaw('count(*) - count(distinct order_id) as d')->whereIn('status', ['proposed', 'accepted', 'picked_up'])->value('d'));

        // Livreurs : courses échouées par la faute du client rémunérées à 50 %, règlements effectués.
        $this->assertGreaterThan(0, DeliveryAssignment::query()->where('status', 'failed')->where('incident_reason', 'client_absent')->where('partner_fee_xof', '>', 0)->count());
        $this->assertSame(0, DeliveryAssignment::query()->where('status', 'failed')->where('incident_reason', 'produit_endommage')->where('partner_fee_xof', '>', 0)->count());
        $this->assertGreaterThan(0, DeliveryAssignment::query()->whereNotNull('settled_at')->count());

        // Avis, historique daté, indicateurs non vides.
        $this->assertGreaterThan(0, Review::query()->where('moderation_status', 'approved')->count());
        $this->assertGreaterThan(20, Order::query()->where('created_at', '<', now()->subDays(7))->count());
        $this->actingAs(User::query()->where('email', 'admin@mk-delices.test')->firstOrFail())
            ->getJson('/api/v1/admin/stats?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertJsonPath('data.kpis.paid_orders', fn ($n) => $n > 20);

        // Géolocalisation : positions réalistes dans Dakar, toutes les origines, saisie sans position, correction par l'équipe.
        $demo = Order::query()->where('internal_notes', DemoActivitySeeder::MARKER)->where('fulfillment_type', 'delivery')->get();
        $positioned = $demo->filter(fn (Order $o) => isset($o->address_snapshot['lat']));
        $this->assertGreaterThan(20, $positioned->count());
        $this->assertGreaterThan(10, $demo->count() - $positioned->count(), 'commandes saisies sans position');
        $area = app(ZoneLocator::class)->serviceArea();
        foreach ($positioned as $order) {
            $this->assertTrue(ZoneArea::contains($area, new GeoPoint($order->address_snapshot['lat'], $order->address_snapshot['lng'])), "{$order->reference} hors de Dakar");
        }
        $sources = $positioned->map(fn (Order $o) => $o->address_snapshot['location_source'] ?? null)->unique()->all();
        foreach (['gps', 'map', 'saved'] as $source) {
            $this->assertContains($source, $sources, "origine « {$source} » présente");
        }
        $this->assertTrue($demo->contains(fn (Order $o) => ($o->address_snapshot['zone_source'] ?? null) === 'staff'), 'commande corrigée par le commercial');
        $this->assertFalse($demo->contains(fn (Order $o) => ($o->address_snapshot['district'] ?? '') === 'Quartier de démonstration'), 'quartiers réels');
        foreach (['client1', 'client2', 'client3'] as $client) {
            $addresses = User::query()->where('email', "{$client}@mk-delices.test")->firstOrFail()->customer->addresses;
            $this->assertSame(1, $addresses->where('is_default', true)->count(), "{$client} : une seule adresse par défaut");
        }

        // Rejouer ne duplique rien.
        $count = Order::query()->count();
        $this->seed(DemoActivitySeeder::class);
        $this->assertSame($count, Order::query()->count());

        // Renouvellement : l'ancienne génération est archivée (jamais supprimée) et disparaît des écrans opérationnels.
        $previous = Order::query()->where('internal_notes', DemoActivitySeeder::MARKER)->pluck('id');
        $previousRefs = Order::query()->whereIn('id', $previous)->pluck('reference')->all();
        $this->artisan('mk:demo-refresh')->assertSuccessful();
        $this->assertSame($previous->count(), Order::query()->whereIn('id', $previous)->count(), 'aucune suppression');
        $this->assertSame($previous->count(), Order::query()->whereIn('id', $previous)->where('is_test', true)->count());
        $this->assertGreaterThan(50, Order::query()->where('internal_notes', DemoActivitySeeder::MARKER)->where('is_test', false)->count());
        $this->assertSame(1, User::query()->where('email', 'client1@mk-delices.test')->firstOrFail()->customer->addresses()->where('is_default', true)->count());

        $courier = User::query()->where('email', 'livreur1@mk-delices.test')->firstOrFail();
        $missions = $this->actingAs($courier)->getJson('/api/v1/courier/missions')->assertOk()->json('data');
        $this->assertNotEmpty(array_merge($missions['active'], $missions['history']), 'missions de la nouvelle génération');
        $this->assertSame([], array_values(array_intersect(array_column(array_merge($missions['active'], $missions['history']), 'order_reference'), $previousRefs)));
        $board = $this->actingAs(User::query()->where('email', 'admin@mk-delices.test')->firstOrFail())->getJson('/api/v1/admin/preparation')->assertOk()->json('data');
        $onBoard = array_merge(...array_map(fn ($column) => array_column($column, 'id'), array_values($board)));
        $this->assertNotEmpty($onBoard);
        $this->assertSame([], array_values(array_intersect($onBoard, $previous->all())));
    }
}
