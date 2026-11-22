<?php

namespace Tests\Feature;

use App\Models\DeliveryAssignment;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit du 22/11/2026, A-01 : une commande non payée ne part jamais en livraison sans montant à encaisser,
 * même si le client a tenté puis abandonné un paiement en ligne (ce qui change le moyen de paiement noté).
 */
class CashCollectionTest extends TestCase
{
    public function test_abandoned_online_attempt_keeps_the_amount_to_collect(): void
    {
        $zone = $this->zone();
        [$order, $token] = $this->createOrder(null, $zone);
        // Commande saisie par l'équipe (téléphone, boutique) : aucun moyen de paiement choisi.
        $order->forceFill(['payment_method' => null])->save();
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->transition($commercial, $order, 'accept')->assertOk();

        // Le client ouvre son lien de suivi, lance un paiement en ligne… puis abandonne.
        $this->postJson("/api/v1/orders/{$order->reference}/payments", ['method' => 'simulator'], ['X-Tracking-Token' => $token, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('simulator', $order->fresh()->payment_method);

        $prep = $this->staff(User::ROLE_PREPARATEUR);
        $this->transition($prep, $order, 'start_preparation')->assertOk();
        $this->transition($prep, $order, 'mark_ready', ['packaging_checked' => true])->assertOk();
        $partner = $this->partner($zone);
        $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))
            ->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->assertCreated();

        $this->assertSame($order->fresh()->total_xof, DeliveryAssignment::query()->where('order_id', $order->id)->sole()->cash_to_collect_xof);
    }

    public function test_paid_order_has_nothing_to_collect(): void
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))
            ->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->assertCreated();

        $this->assertSame(0, DeliveryAssignment::query()->where('order_id', $order->id)->sole()->cash_to_collect_xof);
    }
}
