<?php

namespace Tests\Feature;

use App\Domain\Catalog\CatalogImporter;
use App\Domain\Shared\Settings;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\Promotion;
use Database\Seeders\LaunchConfigurationSeeder;
use Tests\TestCase;

class LaunchConfigurationTest extends TestCase
{
    public function test_validated_launch_configuration_is_applied_and_idempotent(): void
    {
        $demoZone = $this->zone(['is_demo' => true]);
        $demo = $this->variant([], ['slug' => 'produit-demo', 'is_demo' => true])->product;

        $this->seed(LaunchConfigurationSeeder::class);
        $this->seed(LaunchConfigurationSeeder::class); // rejouable sans doublon

        $zones = DeliveryZone::query()->where('is_demo', false)->where('active', true)->orderBy('position')->get();
        $this->assertSame(['Zone 1 — Centre', 'Zone 2 — Dakar intermédiaire', 'Zone 3 — Corniche Ouest', 'Zone 4 — Nord'], $zones->pluck('name')->all());
        $this->assertSame([1500, 2000, 2500, 3000], $zones->pluck('delivery_fee_xof')->all());
        $this->assertSame([5000, 5000, 7500, 10000], $zones->pluck('min_order_xof')->all());

        $settings = app(Settings::class);
        $this->assertSame([], $settings->get('business.hours')['mon'], 'Fermé le lundi');
        $this->assertSame([['10:00', '20:00']], $settings->get('business.hours')['sun']);
        $this->assertSame(30, $settings->get('quality.cold_chain_max_minutes'));
        $this->assertFalse($settings->get('payments.methods')['cash_on_delivery']);

        $promo = Promotion::query()->where('code', 'BIENVENUE10')->firstOrFail();
        $this->assertFalse($promo->active, 'Code créé inactif jusqu\'à l\'ouverture');
        $this->assertSame(2000, $promo->max_discount_xof);
        $this->assertSame(1, Promotion::query()->count());

        $this->assertFalse($demoZone->fresh()->active);
        $this->assertTrue(Product::withTrashed()->find($demo->id)->trashed(), 'Produit de démonstration archivé, pas supprimé');
    }

    public function test_full_launch_sequence_exposes_only_the_validated_catalogue(): void
    {
        $this->variant([], ['slug' => 'ancien-demo', 'is_demo' => true]);
        app(CatalogImporter::class)->import(base_path('../docs/propositions/catalogue-propose.csv'), publish: true);
        $this->seed(LaunchConfigurationSeeder::class);

        $slugs = collect($this->getJson('/api/v1/catalog/products?per_page=50')->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();
        $this->assertSame([
            'coffret-decouverte', 'tiramisu-bissap', 'tiramisu-bouye-caramel', 'tiramisu-chocolat-noisette',
            'tiramisu-classique', 'tiramisu-mangue-citron-vert', 'tiramisu-speculoos', 'truffes-chocolat',
        ], $slugs);
        $this->assertCount(4, $this->getJson('/api/v1/delivery/zones')->json('data'));
    }
}
