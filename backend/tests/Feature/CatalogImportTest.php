<?php

namespace Tests\Feature;

use App\Domain\Catalog\CatalogImporter;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Shared\DomainException;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\ProductVariant;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mk').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$content);

        return $path;
    }

    private const HEADER = 'categorie;produit;slug;accroche;description;ingredients;allergenes;conservation;duree_conservation_heures;chaine_du_froid;signature;mis_en_avant;nouveaute;format;sku;parts;prix_fcfa;delai_preparation_minutes;quantite_max_par_commande';

    public function test_proposed_catalogue_file_imports_as_drafts_and_is_idempotent(): void
    {
        $file = base_path('../docs/propositions/catalogue-propose.csv');
        $report = app(CatalogImporter::class)->import($file);

        $this->assertSame(8, $report['products_created']);
        $this->assertSame(18, $report['variants_created']);
        $this->assertSame(0, Product::query()->where('status', '!=', ProductStatus::Draft->value)->count(), 'Rien n\'est publié sans validation');
        $classic = Product::query()->where('slug', 'tiramisu-classique')->with('variants')->firstOrFail();
        $this->assertTrue($classic->is_signature);
        $this->assertSame(['lait', 'œufs', 'gluten'], $classic->allergens);
        $this->assertSame([2500, 9000, 17000], $classic->variants->pluck('price_xof')->all());
        $this->getJson('/api/v1/catalog/products/tiramisu-classique')->assertNotFound();

        // Rejouer l'import ne crée aucun doublon.
        $again = app(CatalogImporter::class)->import($file);
        $this->assertSame(0, $again['products_created']);
        $this->assertSame(8, $again['products_updated']);
        $this->assertSame(18, ProductVariant::query()->count());
    }

    public function test_publish_option_and_existing_status_kept(): void
    {
        $file = $this->csv(self::HEADER."\nTiramisus;Test;tiramisu-test;;;mascarpone;lait;Au frais;48;oui;non;non;non;Coupe;TST-1;1;2500;240;10\n");
        app(CatalogImporter::class)->import($file, publish: true);
        $this->getJson('/api/v1/catalog/products/tiramisu-test')->assertOk();

        Product::query()->where('slug', 'tiramisu-test')->update(['status' => ProductStatus::Inactive->value]);
        app(CatalogImporter::class)->import($file);
        $this->assertSame(ProductStatus::Inactive, Product::query()->where('slug', 'tiramisu-test')->value('status'));
    }

    public function test_invalid_file_is_rejected_entirely_with_line_numbers(): void
    {
        $file = $this->csv(self::HEADER
            ."\nTiramisus;Bon;bon-produit;;;mascarpone;lait;Au frais;48;oui;non;non;non;Coupe;OK-1;1;2500;240;10"
            ."\nTiramisus;Sans allergènes;sans-allergenes;;;mascarpone;;Au frais;48;oui;non;non;non;Coupe;OK-2;1;abc;240;10"
            ."\nTiramisus;Doublon;doublon;;;mascarpone;lait;Au frais;48;oui;non;non;non;Coupe;OK-1;1;2500;240;10\n");

        try {
            app(CatalogImporter::class)->import($file);
            $this->fail('Import attendu en échec');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Ligne 3 : « allergenes » obligatoire', $e->getMessage());
            $this->assertStringContainsString('Ligne 3 : prix invalide', $e->getMessage());
            $this->assertStringContainsString('Ligne 4 : SKU « OK-1 » en double', $e->getMessage());
        }
        $this->assertSame(0, Product::query()->count(), 'Aucune écriture partielle');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = app(CatalogImporter::class)->import(base_path('../docs/propositions/catalogue-propose.csv'), dryRun: true);
        $this->assertTrue($report['dry_run']);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_content_import_command_loads_validated_legal_text(): void
    {
        ContentPage::query()->create(['slug' => 'conditions-generales', 'title' => 'CGV', 'body' => 'Trame', 'status' => 'published', 'requires_validation' => true, 'published_at' => now()]);
        $file = $this->csv("## 1. Objet\n\nTexte validé par le juriste.");

        $this->artisan('mk:import-content', ['slug' => 'conditions-generales', 'file' => $file])->assertSuccessful();
        $page = ContentPage::query()->where('slug', 'conditions-generales')->first();
        $this->assertStringContainsString('Texte validé', $page->body);
        $this->assertTrue($page->requires_validation, 'Reste marqué « à valider » sans --validated');

        $this->artisan('mk:import-content', ['slug' => 'conditions-generales', 'file' => $file, '--validated' => true])->assertSuccessful();
        $this->assertFalse($page->fresh()->requires_validation);
    }
}
