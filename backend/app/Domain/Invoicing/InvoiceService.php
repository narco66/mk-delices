<?php

namespace App\Domain\Invoicing;

use App\Domain\Orders\OrderJournal;
use App\Domain\Orders\OrderStatus;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Émission des factures et avoirs.
 *
 * - Les prix du catalogue sont TTC : quand la TVA est applicable, elle est EXTRAITE du TTC
 *   (HT = TTC / (1 + taux)), le montant payé par le client ne change jamais.
 * - Numérotation continue sans trou par série et par année (compteur verrouillé dans la même
 *   transaction que l'insertion : un échec annule aussi l'incrément).
 * - Documents immuables : toute correction passe par un avoir, qui reprend le régime de TVA
 *   de la facture d'origine (jamais les paramètres du jour).
 * - Hors production, les séries sont préfixées « TEST- » et le document porte « sans valeur fiscale ».
 */
class InvoiceService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly OrderJournal $journal,
    ) {}

    /** La facture doit-elle être émise automatiquement maintenant ? */
    public function isDue(Order $order): bool
    {
        return ! $order->is_test
            && $this->isInvoiceable($order)
            && ($this->settings->get('invoicing.issue_on', 'delivered') === 'paid' || $order->status === OrderStatus::Delivered)
            && ! $order->invoice()->exists();
    }

    /** Émission automatique (livraison ou paiement) : ne lève pas d'erreur, trace un blocage éventuel. */
    public function issueIfDue(Order $order): ?Invoice
    {
        if (! $this->isDue($order)) {
            return null;
        }
        try {
            return $this->issueForOrder($order, null);
        } catch (DomainException $e) {
            $this->audit->log('invoice.blocked', $order, null, ['code' => $e->errorCode, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** Émet (ou renvoie si elle existe déjà) la facture d'une commande intégralement payée. */
    public function issueForOrder(Order $order, ?User $actor): Invoice
    {
        $invoice = DB::transaction(function () use ($order, $actor) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->with('items')->findOrFail($order->id);
            if ($existing = $locked->invoice()->first()) {
                return $existing;
            }
            if ($locked->is_test) {
                throw DomainException::conflict('test_order', 'Commande de test : aucune facture n\'est émise.');
            }
            if (! $this->isInvoiceable($locked)) {
                throw DomainException::conflict('not_invoiceable', 'La facture ne peut être émise que pour une commande intégralement payée et non annulée.');
            }

            $vat = (bool) $this->settings->get('invoicing.vat_enabled', false);
            $rateBp = $vat ? (int) round(((float) $this->settings->get('invoicing.vat_rate_percent', 18)) * 100) : 0;
            [$ht, $tva] = self::split($locked->total_xof, $rateBp);
            $lines = $this->orderLines($locked);
            if (array_sum(array_column($lines, 'amount_ttc_xof')) !== $locked->total_xof) {
                throw DomainException::conflict('invoice_mismatch', 'Le détail de la commande ne correspond pas à son total.');
            }

            $invoice = $this->create($locked, Invoice::TYPE_INVOICE, [
                'vat_applicable' => $vat,
                'vat_rate_bp' => $rateBp,
                'total_ht_xof' => $ht,
                'total_vat_xof' => $tva,
                'total_ttc_xof' => $locked->total_xof,
                'lines' => $lines,
                'legal_note' => $vat ? null : (string) $this->settings->get('invoicing.vat_exemption_note', 'TVA non applicable'),
            ], $actor);

            // Remboursements déjà effectués avant l'émission : un avoir chacun, pour que le solde soit juste.
            Refund::query()->where('order_id', $locked->id)->where('status', 'succeeded')->where('kind', 'sale')->orderBy('completed_at')->get()
                ->each(fn (Refund $refund) => $this->createCreditNote($invoice, $refund, $actor));

            return $invoice;
        });

        if ($invoice->wasRecentlyCreated) {
            DB::afterCommit(fn () => app(InvoiceNotifier::class)->issued($invoice));
        }

        return $invoice;
    }

    /** Avoir pour un remboursement effectué, si la commande a déjà une facture. */
    public function issueCreditNoteForRefund(Refund $refund, ?User $actor): ?Invoice
    {
        $creditNote = DB::transaction(function () use ($refund, $actor) {
            Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            $invoice = Invoice::query()->where('order_id', $refund->order_id)->where('type', Invoice::TYPE_INVOICE)->first();
            // Un trop-perçu restitué ne réduit pas la vente : pas d'avoir (audit E2).
            if (! $invoice || $refund->status !== 'succeeded' || $refund->kind !== 'sale') {
                return null;
            }

            return $this->createCreditNote($invoice, $refund, $actor);
        });

        if ($creditNote?->wasRecentlyCreated) {
            DB::afterCommit(fn () => app(InvoiceNotifier::class)->issued($creditNote));
        }

        return $creditNote;
    }

    /**
     * Ventilation HT / TVA d'un montant TTC entier (XOF, sans décimales).
     *
     * @return array{0:int, 1:int} [HT, TVA]
     */
    public static function split(int $ttc, int $rateBp): array
    {
        if ($rateBp <= 0) {
            return [$ttc, 0];
        }
        $ht = (int) round($ttc * 10000 / (10000 + $rateBp));

        return [$ht, $ttc - $ht];
    }

    private function isInvoiceable(Order $order): bool
    {
        return ! in_array($order->status, [OrderStatus::Draft, OrderStatus::Cancelled, OrderStatus::Rejected], true)
            && $order->total_xof > 0
            && $order->paid_xof >= $order->total_xof;
    }

    private function createCreditNote(Invoice $invoice, Refund $refund, ?User $actor): Invoice
    {
        if ($existing = Invoice::query()->where('refund_id', $refund->id)->first()) {
            return $existing;
        }
        $alreadyCredited = (int) Invoice::query()->where('original_invoice_id', $invoice->id)->sum('total_ttc_xof');
        if ($alreadyCredited + $refund->amount_xof > $invoice->total_ttc_xof) {
            throw DomainException::conflict('credit_exceeds_invoice', 'Le total des avoirs dépasserait le montant de la facture.');
        }
        [$ht, $tva] = self::split($refund->amount_xof, $invoice->vat_rate_bp);
        $order = $invoice->order;

        return $this->create($order, Invoice::TYPE_CREDIT_NOTE, [
            'original_invoice_id' => $invoice->id,
            'refund_id' => $refund->id,
            'vat_applicable' => $invoice->vat_applicable,
            'vat_rate_bp' => $invoice->vat_rate_bp,
            'total_ht_xof' => $ht,
            'total_vat_xof' => $tva,
            'total_ttc_xof' => $refund->amount_xof,
            'lines' => [[
                'description' => "Remboursement — commande {$order->reference} (facture {$invoice->number})",
                'detail' => mb_substr($refund->reason, 0, 200),
                'qty' => 1,
                'unit_price_ttc_xof' => $refund->amount_xof,
                'amount_ttc_xof' => $refund->amount_xof,
            ]],
            'legal_note' => $invoice->legal_note,
        ], $actor);
    }

    private function create(Order $order, string $type, array $data, ?User $actor): Invoice
    {
        $production = app()->isProduction();
        $series = ($production ? '' : 'TEST-').($type === Invoice::TYPE_INVOICE ? 'FA' : 'AV');
        $issuedAt = now();
        $year = (int) $issuedAt->copy()->timezone(config('mkdelices.business_timezone'))->format('Y');
        $sequence = $this->nextSequence($series, $year);

        $invoice = Invoice::query()->create($data + [
            'order_id' => $order->id,
            'type' => $type,
            'number' => sprintf('%s-%d-%05d', $series, $year, $sequence),
            'series' => $series,
            'year' => $year,
            'sequence' => $sequence,
            'issued_at' => $issuedAt,
            'currency' => $order->currency ?? 'XOF',
            'seller' => $this->seller($production),
            'buyer' => $this->buyer($order),
            'is_test' => ! $production,
            'issued_by' => $actor?->id,
        ]);

        $this->journal->record($order, 'invoice', $actor, null, ['number' => $invoice->number, 'type' => $type, 'total_ttc_xof' => $invoice->total_ttc_xof]);
        $this->audit->log("invoice.{$type}_issued", $invoice, null, ['number' => $invoice->number, 'order' => $order->reference, 'total_ttc_xof' => $invoice->total_ttc_xof]);

        return $invoice;
    }

    /** Incrément atomique : la ligne du compteur reste verrouillée jusqu'à la fin de la transaction. */
    private function nextSequence(string $series, int $year): int
    {
        $row = DB::selectOne(
            'INSERT INTO invoice_sequences (series, year, last_number) VALUES (?, ?, 1)
             ON CONFLICT (series, year) DO UPDATE SET last_number = invoice_sequences.last_number + 1
             RETURNING last_number',
            [$series, $year],
        );

        return (int) $row->last_number;
    }

    /** Mentions vendeur figées dans le document. En production, les mentions légales sont obligatoires. */
    private function seller(bool $production): array
    {
        $seller = [
            'name' => $this->settings->get('business.name', 'MK Délices'),
            'legal_name' => $this->settings->get('business.legal_name'),
            'address' => $this->settings->get('business.address'),
            'ninea' => $this->settings->get('business.ninea'),
            'rccm' => $this->settings->get('business.rccm'),
            'phone' => $this->settings->get('business.phone'),
            'email' => $this->settings->get('business.email'),
            'footer' => $this->settings->get('invoicing.footer'),
        ];
        $missing = array_keys(array_filter(['raison sociale' => $seller['legal_name'], 'NINEA' => $seller['ninea'], 'adresse' => $seller['address']], fn ($v) => blank($v)));
        if ($production && $missing !== []) {
            throw DomainException::conflict('invoicing_not_configured', 'Mentions légales manquantes pour facturer : '.implode(', ', $missing).' (Back-office › Paramètres).');
        }

        return $seller;
    }

    /** Acheteur : identité et adresse de livraison, sans téléphone (le lien de suivi peut être partagé). */
    private function buyer(Order $order): array
    {
        $c = $order->customer_snapshot ?? [];
        $a = $order->address_snapshot ?? [];

        return [
            'name' => trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')),
            'email' => $c['email'] ?? null,
            'address' => implode(', ', array_filter([$a['street'] ?? null, $a['district'] ?? null, $a['zone_name'] ?? null])) ?: null,
            'order_reference' => $order->reference,
            'order_date' => $order->created_at?->toIso8601ZuluString(),
            'delivered_at' => $order->delivered_at?->toIso8601ZuluString(),
            'payment_method' => $order->payment_method,
            'paid_at' => ($paidAt = $order->payments()->where('status', 'succeeded')->max('verified_at')) ? Carbon::parse($paidAt)->toIso8601ZuluString() : null,
        ];
    }

    /** Lignes TTC : articles, livraison, remise (négative). */
    private function orderLines(Order $order): array
    {
        $lines = $order->items->map(fn ($i) => [
            'description' => $i->product_name_snapshot.' — '.$i->variant_label_snapshot,
            'detail' => $i->sku_snapshot,
            'qty' => $i->qty,
            'unit_price_ttc_xof' => $i->unit_price_xof,
            'amount_ttc_xof' => $i->line_total_xof,
        ])->all();
        if ($order->delivery_fee_xof > 0) {
            $lines[] = [
                'description' => 'Livraison'.(($order->address_snapshot['zone_name'] ?? null) ? ' — '.$order->address_snapshot['zone_name'] : ''),
                'detail' => null, 'qty' => 1,
                'unit_price_ttc_xof' => $order->delivery_fee_xof, 'amount_ttc_xof' => $order->delivery_fee_xof,
            ];
        }
        if ($order->discount_xof > 0) {
            $lines[] = [
                'description' => 'Remise'.($order->promotion_code ? " (code {$order->promotion_code})" : ''),
                'detail' => null, 'qty' => 1,
                'unit_price_ttc_xof' => -$order->discount_xof, 'amount_ttc_xof' => -$order->discount_xof,
            ];
        }

        return $lines;
    }
}
