<?php

namespace Tests\Feature;

use App\Domain\Catalog\ProductStatus;
use Tests\TestCase;

/** NFR-009 — métadonnées SEO présentes dans le HTML initial du SPA. */
class SeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $index = storage_path('framework/testing-spa-index.html');
        file_put_contents($index, '<!doctype html><html><head><title>Défaut</title><meta name="description" content="x"></head><body><div id="root"></div></body></html>');
        config(['mkdelices.spa_index_path' => $index, 'mkdelices.frontend_url' => 'https://www.exemple.test']);
    }

    public function test_product_page_html_contains_product_metadata_and_jsonld(): void
    {
        $variant = $this->variant(['price_xof' => 2500], ['name' => 'Tiramisu SEO', 'slug' => 'tiramisu-seo', 'seo_description' => 'Description SEO']);
        // Plusieurs formats : vérifie l'absence de chargement paresseux (mode strict).
        $variant->product->variants()->create(['sku' => 'SEO-8P', 'label' => '8 parts', 'price_xof' => 9000]);

        $html = $this->get('/produits/tiramisu-seo')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Tiramisu SEO · MK Délices</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://www.exemple.test/produits/tiramisu-seo">', $html);
        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"priceCurrency":"XOF"', $html);
        $this->assertStringContainsString($variant->sku, $html);
        $this->assertSame(1, substr_count($html, '<title>'));
    }

    public function test_unknown_product_returns_404_noindex_and_private_pages_are_noindex(): void
    {
        $this->get('/produits/inconnu')->assertNotFound()->assertSee('noindex', false);
        $this->get('/admin/commandes')->assertOk()->assertSee('noindex, nofollow', false);
    }

    public function test_sitemap_lists_published_products_and_robots_blocks_non_production(): void
    {
        $this->variant([], ['slug' => 'publie']);
        $this->variant([], ['slug' => 'brouillon', 'status' => ProductStatus::Draft]);
        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->getContent();
        $this->assertStringContainsString('/produits/publie', $xml);
        $this->assertStringNotContainsString('/produits/brouillon', $xml);
        $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false);
    }

    public function test_api_routes_are_not_captured_by_the_spa(): void
    {
        $this->getJson('/api/v1/inexistant')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
}
