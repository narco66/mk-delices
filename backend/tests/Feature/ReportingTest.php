<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/** STATS-010 — T-010 indicateurs sur ventes payées non remboursées ; T-024 recherche par période */
class ReportingTest extends TestCase
{
    public function test_kpis_only_count_paid_orders_net_of_refunds_and_exclude_test_orders(): void
    {
        $variant = $this->variant(['price_xof' => 5000]);
        $zone = $this->zone(['delivery_fee_xof' => 1000]);

        [$paid, $t1] = $this->createOrder($variant, $zone);      // 2×5000 + 1000 = 11000
        $this->payOrder($paid, $t1);
        [$unpaid] = $this->createOrder($variant, $zone);          // non payée : exclue
        [$testOrder, $t3] = $this->createOrder($variant, $zone);  // commande de test : exclue
        $this->payOrder($testOrder, $t3);
        $testOrder->update(['is_test' => true]);

        $today = now('Africa/Dakar')->toDateString();
        $response = $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->getJson("/api/v1/admin/stats?from={$today}&to={$today}")->assertOk();

        $response->assertJsonPath('data.kpis.revenue_net_xof', 11000)
            ->assertJsonPath('data.kpis.paid_orders', 1)
            ->assertJsonPath('data.kpis.average_basket_xof', 11000)
            ->assertJsonPath('data.kpis.confirmed_orders', 2)
            ->assertJsonPath('data.kpis.conversion_rate', null)
            ->assertJsonPath('data.top_products.0.quantity', 2);
        $this->assertSame(11000, collect($response->json('data.timeseries'))->sum('revenue_net_xof'));

        $this->getJson("/api/v1/admin/orders?payment_status=paid&from={$today}&to={$today}")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $paid->reference);
        $this->assertNotNull($unpaid);
    }

    public function test_dashboard_operations_counters(): void
    {
        $this->createOrder();
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.operations.to_confirm', 1)->assertJsonPath('data.operations.payments_pending', 0);
    }
}
