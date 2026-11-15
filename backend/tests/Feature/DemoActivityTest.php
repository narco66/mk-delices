<?php

namespace Tests\Feature;

use App\Domain\Catalog\CatalogImporter;
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

        // Rejouer ne duplique rien.
        $count = Order::query()->count();
        $this->seed(DemoActivitySeeder::class);
        $this->assertSame($count, Order::query()->count());
    }
}
