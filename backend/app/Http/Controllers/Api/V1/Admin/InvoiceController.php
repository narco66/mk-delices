<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\InvoiceService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Back-office : registre des factures et avoirs, téléchargement, émission manuelle, export comptable. */
class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request)->with('order:id,reference')->orderByDesc('issued_at')->orderByDesc('sequence');
        $page = $query->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($page->items())->map(fn (Invoice $i) => $i->summary() + [
                'order_reference' => $i->order?->reference,
                'order_id' => $i->order_id,
                'buyer_name' => $i->buyer['name'] ?? null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf): Response
    {
        return self::pdfResponse($invoice, $pdf);
    }

    public function issue(Request $request, Order $order, InvoiceService $invoices): JsonResponse
    {
        $invoice = $invoices->issueForOrder($order, $request->user());

        return response()->json(['data' => $invoice->summary()], $invoice->wasRecentlyCreated ? 201 : 200);
    }

    /** Export CSV (séparateur « ; », UTF-8 avec BOM pour Excel) : une ligne par document, avoirs en négatif. */
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request)->with('order:id,reference', 'originalInvoice:id,number')->orderBy('issued_at')->orderBy('sequence');
        $tz = config('mkdelices.business_timezone');

        return response()->streamDownload(function () use ($rows, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Numéro', 'Type', 'Date', 'Commande', 'Client', "Facture d'origine", 'TVA applicable', 'Taux TVA (%)', 'Total HT (XOF)', 'TVA (XOF)', 'Total TTC (XOF)', 'Document de test'], ';');
            $rows->chunk(500, function ($chunk) use ($out, $tz) {
                foreach ($chunk as $i) {
                    $sign = $i->isCreditNote() ? -1 : 1;
                    fputcsv($out, [
                        $i->number, $i->label(), $i->issued_at->timezone($tz)->format('Y-m-d'),
                        $i->order?->reference, self::csvText($i->buyer['name'] ?? ''), $i->originalInvoice?->number ?? '',
                        $i->vat_applicable ? 'oui' : 'non', number_format($i->vat_rate_bp / 100, 2, ',', ''),
                        $sign * $i->total_ht_xof, $sign * $i->total_vat_xof, $sign * $i->total_ttc_xof, $i->is_test ? 'oui' : 'non',
                    ], ';');
                }
            });
            fclose($out);
        }, 'factures-mk-delices.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Texte saisi par un tiers : neutralise les formules de tableur (=, +, -, @, tabulation, retour chariot). Audit M2. */
    private static function csvText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    public static function pdfResponse(Invoice $invoice, InvoicePdf $pdf): Response
    {
        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->fileName().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return Builder<Invoice> */
    private function filtered(Request $request): Builder
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:invoice,credit_note'],
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $tz = config('mkdelices.business_timezone');

        return Invoice::query()
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'ilike', '%'.$s.'%')
                ->orWhereHas('order', fn ($o) => $o->where('reference', 'ilike', '%'.$s.'%'))))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('issued_at', '>=', Carbon::parse($d, $tz)->startOfDay()))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('issued_at', '<=', Carbon::parse($d, $tz)->endOfDay()));
    }
}
