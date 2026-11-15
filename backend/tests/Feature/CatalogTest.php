<?php

namespace Tests\Feature;

use App\Domain\Catalog\ProductStatus;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\User;
use Tests\TestCase;

/** CAT-001 / T-001 */
class CatalogTest extends TestCase
{
    public function test_public_catalog_lists_only_published_products_with_allergens(): void
    {
        $visible = $this->variant([], ['name' => 'Visible']);
        $this->variant([], ['name' => 'Brouillon', 'status' => ProductStatus::Draft]);
        $this->variant([], ['name' => 'Désactivé', 'status' => ProductStatus::Inactive]);

        $response = $this->getJson('/api/v1/catalog/products')->assertOk();

        $this->assertSame(['Visible'], array_column($response->json('data'), 'name'));
        $this->assertSame(['lait', 'œufs', 'gluten'], $response->json('data.0.allergens'));
        $this->assertSame(2500, $response->json('data.0.variants.0.price_xof'));

        $this->getJson('/api/v1/catalog/products/'.$visible->product->slug)
            ->assertOk()->assertJsonPath('data.ingredients', 'Mascarpone, œufs')->assertJsonPath('data.storage_instructions', 'Au frais');
    }

    public function test_draft_product_page_is_not_found(): void
    {
        $draft = $this->variant([], ['status' => ProductStatus::Draft]);
        $this->getJson('/api/v1/catalog/products/'.$draft->product->slug)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_disabled_product_cannot_be_added_nor_ordered(): void
    {
        $variant = $this->variant();
        $zone = $this->zone();
        $cart = $this->cartWith($variant);
        $slot = $this->firstSlot($cart);

        // Désactivation par l'administration après ajout au panier.
        $variant->product->update(['status' => ProductStatus::Inactive]);

        $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1], $this->cartHeaders(...$cart))
            ->assertStatus(422)->assertJsonPath('error.code', 'variant_unavailable');
        $this->postJson('/api/v1/checkout/orders', $this->orderPayload($zone, $slot), $this->cartHeaders(...$cart) + ['Idempotency-Key' => 'disabled-product-1'])
            ->assertStatus(409)->assertJsonPath('error.code', 'variant_unavailable');
    }

    public function test_search_filter_and_sort(): void
    {
        $this->variant(['price_xof' => 5000], ['name' => 'Tiramisu spéculoos']);
        $this->variant(['price_xof' => 2000], ['name' => 'Tiramisu classique']);

        $this->getJson('/api/v1/catalog/products?q=spécul')->assertOk()->assertJsonCount(1, 'data');
        $names = array_column($this->getJson('/api/v1/catalog/products?sort=price_asc')->json('data'), 'name');
        $this->assertSame(['Tiramisu classique', 'Tiramisu spéculoos'], $names);
    }

    public function test_admin_can_create_and_publish_product_and_price_change_is_audited(): void
    {
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $category = Category::query()->create(['name' => 'Gâteaux', 'slug' => 'gateaux', 'active' => true]);
        $payload = [
            'category_id' => $category->id, 'name' => 'Fraisier', 'allergens' => ['lait'], 'ingredients' => 'Fraises, crème',
            'variants' => [['sku' => 'FRA-6P', 'label' => '6 parts', 'price_xof' => 12000, 'available' => true]],
        ];
        $id = $this->actingAs($commercial)->postJson('/api/v1/admin/products', $payload)->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->getJson('/api/v1/catalog/products/fraisier')->assertNotFound();

        $this->postJson("/api/v1/admin/products/{$id}/status", ['status' => 'published'])->assertOk();
        $this->getJson('/api/v1/catalog/products/fraisier')->assertOk();

        $variantId = ProductVariant::query()->where('sku', 'FRA-6P')->value('id');
        $payload['variants'][0] += ['id' => $variantId];
        $payload['variants'][0]['price_xof'] = 13000;
        $this->patchJson("/api/v1/admin/products/{$id}", $payload)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.updated', 'target_id' => $id]);
    }

    public function test_preparateur_cannot_manage_catalog(): void
    {
        $this->actingAs($this->staff(User::ROLE_PREPARATEUR))->getJson('/api/v1/admin/products')->assertForbidden();
    }
}
