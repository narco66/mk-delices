<?php

namespace Tests\Feature;

use App\Domain\Invoicing\InvoiceService;
use App\Domain\Shared\Settings;
use App\Jobs\IssueInvoice;
use App\Models\AuditLog;
use App\Models\DeliveryZone;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Facturation : émission automatique, TVA optionnelle, numérotation continue, avoirs, immuabilité, accès. */
class InvoiceTest extends TestCase
{
    /** Commande payée puis livrée par le flux réel livreur ; renvoie [commande, jeton de suivi]. */
    private function deliveredOrder(?DeliveryZone $zone = null): array
    {
        $zone ??= $this->zone();
        [$order, $token] = $this->createOrder(null, $zone, ['customer' => ['email' => 'awa@example.test', 'last_name' => 'Diop']]);
        $this->payOrder($order, $token);
        $this->transition($this->staff(User::ROLE_COMMERCIAL), $order, 'accept')->assertOk();
        $prep = $this->staff(User::ROLE_PREPARATEUR);
        $this->transition($prep, $order, 'start_preparation')->assertOk();
        $this->transition($prep, $order, 'mark_ready', ['packaging_checked' => true])->assertOk();
        $partner = $this->partner($zone);
        $missionId = $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))
            ->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->assertCreated()->json('data.id');
        $this->actingAs($partner->user);
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'accept'])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'pick_up', 'packaging_checked' => true])->assertOk();
        $this->postJson("/api/v1/courier/missions/{$missionId}/actions", ['action' => 'deliver', 'proof_type' => 'recipient_confirmation', 'proof_reference' => 'Remis'])->assertOk();

        return [$order->fresh(), $token];
    }

    public function test_invoice_without_vat_is_issued_on_delivery_and_downloadable_by_owner_only(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $this->assertSame(0, Invoice::query()->count(), 'Par défaut, la facture est émise à la livraison, pas au paiement.');

        [$order, $token] = $this->deliveredOrder();
        $invoice = Invoice::query()->where('order_id', $order->id)->sole();
        $year = now()->year;
        $this->assertSame("TEST-FA-{$year}-00001", $invoice->number, 'Hors production : série TEST, numérotation à partir de 1.');
        $this->assertTrue($invoice->is_test);
        $this->assertFalse($invoice->vat_applicable);
        $this->assertSame('TVA non applicable', $invoice->legal_note);
        $this->assertSame($order->total_xof, $invoice->total_ttc_xof);
        $this->assertSame($order->total_xof, $invoice->total_ht_xof);
        $this->assertSame(0, $invoice->total_vat_xof);
        $this->assertSame($order->total_xof, array_sum(array_column($invoice->lines, 'amount_ttc_xof')));
        $this->assertSame('Awa Diop', $invoice->buyer['name']);
        $this->assertArrayNotHasKey('phone', $invoice->buyer);
        $this->assertDatabaseHas('notification_logs', ['dedupe_key' => "invoice:{$invoice->id}", 'channel' => 'mail']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'invoice']);

        // Le client la voit et la télécharge avec son jeton ; sans jeton : 404 indistinct.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/orders/{$order->reference}/status", ['X-Tracking-Token' => $token])->assertOk()
            ->assertJsonPath('data.invoices.0.number', $invoice->number);
        $pdf = $this->get("/api/v1/orders/{$order->reference}/invoices/{$invoice->number}", ['X-Tracking-Token' => $token])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->getJson("/api/v1/orders/{$order->reference}/invoices/{$invoice->number}")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->reference}/invoices/{$invoice->number}", ['X-Tracking-Token' => 'faux-jeton-'.str_repeat('x', 30)])->assertNotFound();
    }

    public function test_vat_is_extracted_from_inclusive_prices_and_numbering_is_continuous(): void
    {
        app(Settings::class)->set('invoicing.vat_enabled', true);
        app(Settings::class)->set('invoicing.vat_rate_percent', 18);

        [$first] = $this->deliveredOrder();
        [$second] = $this->deliveredOrder();
        $a = Invoice::query()->where('order_id', $first->id)->sole();
        $b = Invoice::query()->where('order_id', $second->id)->sole();

        // 2 × 2 500 + 1 500 de livraison = 6 500 TTC → HT = round(6 500 / 1,18) = 5 508, TVA = 992. Le client paie toujours 6 500.
        $this->assertSame(6500, $first->total_xof);
        $this->assertTrue($a->vat_applicable);
        $this->assertSame(1800, $a->vat_rate_bp);
        $this->assertSame([5508, 992, 6500], [$a->total_ht_xof, $a->total_vat_xof, $a->total_ttc_xof]);
        $this->assertNull($a->legal_note);
        $this->assertSame($a->sequence + 1, $b->sequence, 'Numérotation continue, sans trou.');
        $this->assertSame([0, 0], InvoiceService::split(0, 1800));
        $this->assertSame([1000, 0], InvoiceService::split(1000, 0));
    }

    public function test_invoices_are_immutable_and_unique_per_order(): void
    {
        [$order] = $this->deliveredOrder();
        $invoice = Invoice::query()->where('order_id', $order->id)->sole();

        // Réémission : la même facture est renvoyée, aucun nouveau numéro consommé.
        $again = app(InvoiceService::class)->issueForOrder($order, null);
        $this->assertSame($invoice->id, $again->id);
        $this->assertSame(1, (int) DB::table('invoice_sequences')->where('series', 'TEST-FA')->value('last_number'));

        try {
            DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total_ttc_xof' => 1]));
            $this->fail('Une facture émise ne doit pas pouvoir être modifiée.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immuable', $e->getMessage());
        }
        // Audit I7 : pas davantage de vidage global de la table (TRUNCATE).
        foreach (['invoices', 'audit_logs', 'order_events'] as $table) {
            try {
                DB::transaction(fn () => DB::statement("TRUNCATE {$table} CASCADE"));
                $this->fail("TRUNCATE {$table} aurait dû être refusé.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('immuable', $e->getMessage());
            }
        }
    }

    public function test_refund_after_invoicing_issues_credit_note_with_original_vat_regime(): void
    {
        app(Settings::class)->set('invoicing.vat_enabled', true);
        [$order] = $this->deliveredOrder();
        $invoice = Invoice::query()->where('order_id', $order->id)->sole();
        // Changer les paramètres ensuite ne modifie pas le régime de l'avoir.
        app(Settings::class)->set('invoicing.vat_enabled', false);

        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $refundId = $this->actingAs($commercial)->postJson("/api/v1/admin/orders/{$order->id}/refunds", ['amount_xof' => 2500, 'reason' => 'Une coupe abîmée à la livraison'])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->staff(User::ROLE_ADMIN))->postJson("/api/v1/admin/refunds/{$refundId}/approve")->assertOk()->assertJsonPath('data.status', 'succeeded');

        $credit = Invoice::query()->where('type', 'credit_note')->sole();
        $this->assertSame('TEST-AV-'.now()->year.'-00001', $credit->number);
        $this->assertSame($invoice->id, $credit->original_invoice_id);
        $this->assertSame($refundId, $credit->refund_id);
        $this->assertTrue($credit->vat_applicable);
        $this->assertSame([2119, 381, 2500], [$credit->total_ht_xof, $credit->total_vat_xof, $credit->total_ttc_xof]);

        // Les avoirs ne peuvent pas dépasser la facture.
        $this->assertSame(0, Invoice::query()->where('type', 'credit_note')->where('total_ttc_xof', '>', $invoice->total_ttc_xof)->count());
    }

    public function test_refund_before_invoicing_is_offset_when_invoice_is_issued(): void
    {
        app(Settings::class)->set('invoicing.issue_on', 'delivered');
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $refundId = $this->actingAs($commercial)->postJson("/api/v1/admin/orders/{$order->id}/refunds", ['amount_xof' => 1000, 'reason' => 'Geste commercial avant livraison'])->assertCreated()->json('data.id');
        $this->actingAs($this->staff(User::ROLE_ADMIN))->postJson("/api/v1/admin/refunds/{$refundId}/approve")->assertOk();
        $this->assertSame(0, Invoice::query()->count());

        $this->actingAs($commercial)->postJson("/api/v1/admin/orders/{$order->id}/invoice")->assertCreated();
        $this->assertSame(1, Invoice::query()->where('type', 'invoice')->count());
        $this->assertSame(1000, (int) Invoice::query()->where('type', 'credit_note')->sum('total_ttc_xof'));
    }

    public function test_issue_on_payment_setting_and_manual_issue_permissions(): void
    {
        app(Settings::class)->set('invoicing.issue_on', 'paid');
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count(), 'Émise dès le paiement confirmé.');

        app(Settings::class)->set('invoicing.issue_on', 'delivered');
        [$unpaid] = $this->createOrder();
        $commercial = $this->staff(User::ROLE_COMMERCIAL);
        $this->actingAs($commercial)->postJson("/api/v1/admin/orders/{$unpaid->id}/invoice")->assertStatus(409)->assertJsonPath('error.code', 'not_invoiceable');

        [$paid, $token2] = $this->createOrder();
        $this->payOrder($paid, $token2);
        $this->actingAs($this->staff(User::ROLE_PREPARATEUR))->postJson("/api/v1/admin/orders/{$paid->id}/invoice")->assertForbidden();
        $number = $this->actingAs($commercial)->postJson("/api/v1/admin/orders/{$paid->id}/invoice")->assertCreated()->json('data.number');
        $this->postJson("/api/v1/admin/orders/{$paid->id}/invoice")->assertOk()->assertJsonPath('data.number', $number);
        $this->getJson("/api/v1/admin/orders/{$paid->id}")->assertOk()->assertJsonPath('data.invoices.0.number', $number)->assertJsonPath('data.can_issue_invoice', false);

        // Registre, PDF et export comptable.
        $this->getJson('/api/v1/admin/invoices?type=invoice')->assertOk()->assertJsonPath('meta.total', 2);
        $this->get('/api/v1/admin/invoices/'.Invoice::query()->where('number', $number)->value('id').'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $csv = $this->get('/api/v1/admin/invoices/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($number, $csv);
        $this->actingAs($this->staff(User::ROLE_LIVREUR))->getJson('/api/v1/admin/invoices')->assertForbidden();
    }

    public function test_catch_up_command_issues_missing_invoices_once(): void
    {
        app(Settings::class)->set('invoicing.issue_on', 'paid');
        [$order, $token] = $this->createOrder();
        Queue::fake([IssueInvoice::class]); // l'émission automatique n'a pas lieu (ex. worker arrêté, mentions manquantes)
        $this->payOrder($order, $token);
        $this->assertSame(0, Invoice::query()->count());

        $this->artisan('mk:issue-invoices', ['--dry-run' => true])->expectsOutputToContain('1 facture(s) à émettre')->assertSuccessful();
        $this->assertSame(0, Invoice::query()->count());
        $this->artisan('mk:issue-invoices')->expectsOutputToContain('1 facture(s) émise(s) sur 1')->assertSuccessful();
        $this->artisan('mk:issue-invoices')->expectsOutputToContain('0 facture(s) émise(s) sur 0')->assertSuccessful();
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count());
    }

    /** Audit M2 : un nom saisi par un client ne doit jamais s'exécuter comme formule dans Excel. */
    public function test_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        app(Settings::class)->set('invoicing.issue_on', 'paid');
        [$order, $token] = $this->createOrder(null, null, ['customer' => ['first_name' => '=HYPERLINK("http://x")', 'last_name' => '+Diop']]);
        $this->payOrder($order, $token);

        $csv = $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->get('/api/v1/admin/invoices/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
        $this->assertStringNotContainsString(';"=HYPERLINK', $csv);
    }

    public function test_test_orders_are_never_invoiced(): void
    {
        app(Settings::class)->set('invoicing.issue_on', 'paid');
        [$order, $token] = $this->createOrder();
        Order::query()->whereKey($order->id)->update(['is_test' => true]);
        $this->payOrder($order, $token);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_production_requires_legal_mentions_and_uses_the_official_series(): void
    {
        [$order, $token] = $this->createOrder();
        $this->payOrder($order, $token);
        $service = app(InvoiceService::class);
        app(Settings::class)->set('invoicing.issue_on', 'paid');
        $this->app['env'] = 'production';
        try {
            // Mentions légales absentes : émission automatique bloquée et tracée, aucun numéro consommé.
            $this->assertNull($service->issueIfDue($order->fresh()));
            $this->assertTrue(AuditLog::query()->where('action', 'invoice.blocked')->exists());
            $this->assertSame(0, (int) DB::table('invoice_sequences')->sum('last_number'));

            $settings = app(Settings::class);
            $settings->set('business.legal_name', 'MK Délices SARL (test)');
            $settings->set('business.ninea', '000000000');
            $settings->set('business.address', 'Dakar');
            $invoice = $service->issueIfDue($order->fresh());
            $this->assertSame('FA-'.now()->year.'-00001', $invoice->number);
            $this->assertFalse($invoice->is_test);
            $this->assertSame('MK Délices SARL (test)', $invoice->seller['legal_name']);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
