<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Tests\TestCase;

/**
 * Appelle chaque endpoint GET avec PLUSIEURS enregistrements par collection : le mode strict
 * (interdiction du chargement paresseux) ne se déclenche qu'à partir de deux modèles.
 */
class EndpointsSmokeTest extends TestCase
{
    public function test_every_read_endpoint_answers_without_server_error(): void
    {
        $zone = $this->zone();
        $orders = [$this->readyOrder($zone), $this->readyOrder($zone)];
        $partner = $this->partner($zone);
        $manager = $this->staff(User::ROLE_RESP_LIVRAISON);
        foreach ($orders as $order) {
            $id = $this->actingAs($manager)->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->assertCreated()->json('data.id');
            $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept'])->assertOk();
            $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();
            $this->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'deliver', 'proof_type' => 'code', 'proof_reference' => $order->fresh()->delivery_code])->assertOk();
            Review::query()->create(['order_id' => $order->id, 'product_id' => $order->items()->value('product_id'), 'author_name' => 'A', 'stars' => 5, 'moderation_status' => 'approved']);
        }
        $this->createOrder(null, $zone);
        $this->variant(); // second produit au catalogue
        $customerId = $orders[0]->customer_id;
        $missionId = $partner->assignments()->value('id');
        $productId = $orders[0]->items()->value('product_id');
        $productSlug = Product::query()->whereKey($productId)->value('slug');

        $admin = $this->staff(User::ROLE_ADMIN);
        $endpoints = [
            '/api/v1/admin/dashboard', '/api/v1/admin/stats?from=2026-01-01&to=2026-12-31', '/api/v1/admin/notifications',
            '/api/v1/admin/orders', '/api/v1/admin/orders?status=delivered&q=MKD', "/api/v1/admin/orders/{$orders[0]->id}",
            '/api/v1/admin/preparation', '/api/v1/admin/payments', '/api/v1/admin/payments/events', '/api/v1/admin/refunds',
            '/api/v1/admin/products', "/api/v1/admin/products/{$productId}", '/api/v1/admin/categories', '/api/v1/admin/promotions',
            '/api/v1/admin/reviews', '/api/v1/admin/content/pages', '/api/v1/admin/content/blocks', '/api/v1/admin/customers',
            "/api/v1/admin/customers/{$customerId}", '/api/v1/admin/users', '/api/v1/admin/settings', '/api/v1/admin/audit-logs',
            '/api/v1/admin/delivery/zones', '/api/v1/admin/delivery/board', '/api/v1/admin/delivery/missions',
            '/api/v1/admin/delivery/partners', '/api/v1/admin/delivery/payouts',
        ];
        foreach ($endpoints as $uri) {
            $status = $this->actingAs($admin)->getJson($uri)->getStatusCode();
            $this->assertSame(200, $status, "GET {$uri} → {$status}");
        }

        foreach (['/api/v1/courier/missions', "/api/v1/courier/missions/{$missionId}"] as $uri) {
            $this->actingAs($partner->user)->getJson($uri)->assertOk();
        }
        foreach (['/api/v1/config', '/api/v1/content/home', '/api/v1/catalog/categories', '/api/v1/catalog/products', "/api/v1/catalog/products/{$productSlug}", '/api/v1/delivery/zones', '/api/v1/promotions'] as $uri) {
            $this->getJson($uri)->assertOk();
        }
    }
}
