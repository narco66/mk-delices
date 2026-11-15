@php
    use App\Domain\Invoicing\InvoicePdf;
    $s = $invoice->seller;
    $b = $invoice->buyer;
    $tz = config('mkdelices.business_timezone');
    $credit = $invoice->isCreditNote();
    $methods = ['wave' => 'Wave', 'orange_money' => 'Orange Money', 'card' => 'Carte bancaire', 'cash_on_delivery' => 'Paiement à la livraison', 'simulator' => 'Paiement simulé (test)'];
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $invoice->label() }} {{ $invoice->number }}</title>
<style>
    @page { margin: 28px 34px 60px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #2b1d1a; }
    h1 { font-size: 20px; margin: 0; color: #4a2c26; }
    .muted { color: #6b5650; }
    .small { font-size: 8.5px; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .box { border: 1px solid #e6d9cf; border-radius: 4px; padding: 8px 10px; }
    .lines th { background: #4a2c26; color: #fff; text-align: left; padding: 6px; font-weight: bold; }
    .lines td { padding: 6px; border-bottom: 1px solid #eee3da; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .totals td { padding: 4px 6px; }
    .totals .grand td { font-size: 12px; font-weight: bold; border-top: 2px solid #4a2c26; }
    .test { color: #a3122e; border: 2px solid #a3122e; padding: 6px; text-align: center; font-weight: bold; margin-bottom: 10px; }
    footer { position: fixed; bottom: -40px; left: 0; right: 0; text-align: center; font-size: 8px; color: #6b5650; }
</style>
</head>
<body>
@if ($invoice->is_test)
    <div class="test">DOCUMENT DE TEST — SANS VALEUR FISCALE (environnement hors production)</div>
@endif

<table class="head">
    <tr>
        <td style="width:55%">
            <h1>{{ $s['name'] ?? 'MK Délices' }}</h1>
            <div>{{ $s['legal_name'] ?? '[Raison sociale à compléter]' }}</div>
            <div>{{ $s['address'] ?? '[Adresse à compléter]' }}</div>
            <div>NINEA : {{ $s['ninea'] ?? '[à compléter]' }}@if (! empty($s['rccm'])) · RCCM : {{ $s['rccm'] }}@endif</div>
            @if (! empty($s['phone']) || ! empty($s['email']))
                <div class="muted">{{ implode(' · ', array_filter([$s['phone'] ?? null, $s['email'] ?? null])) }}</div>
            @endif
        </td>
        <td style="width:45%; text-align:right">
            <h1>{{ mb_strtoupper($invoice->label()) }}</h1>
            <div style="font-size:12px; font-weight:bold">N° {{ $invoice->number }}</div>
            <div>Date d'émission : {{ $invoice->issued_at->timezone($tz)->format('d/m/Y') }}</div>
            @if ($credit && $invoice->originalInvoice)
                <div>Avoir sur la facture {{ $invoice->originalInvoice->number }}</div>
            @endif
            <div>Commande : {{ $b['order_reference'] ?? '' }}</div>
        </td>
    </tr>
</table>

<table style="margin-top:16px">
    <tr>
        <td style="width:50%"></td>
        <td style="width:50%" class="box">
            <div class="muted small">{{ $credit ? 'Bénéficiaire' : 'Facturé à' }}</div>
            <div style="font-weight:bold">{{ $b['name'] ?: 'Client' }}</div>
            @if (! empty($b['address']))<div>{{ $b['address'] }}</div>@endif
            @if (! empty($b['email']))<div>{{ $b['email'] }}</div>@endif
        </td>
    </tr>
</table>

<table class="lines" style="margin-top:16px">
    <thead>
        <tr>
            <th>Désignation</th>
            <th class="num" style="width:40px">Qté</th>
            <th class="num" style="width:90px">Prix unitaire{{ $invoice->vat_applicable ? ' TTC' : '' }}</th>
            <th class="num" style="width:90px">Montant{{ $invoice->vat_applicable ? ' TTC' : '' }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line['description'] }}@if (! empty($line['detail']))<div class="muted small">{{ $line['detail'] }}</div>@endif</td>
                <td class="num">{{ $line['qty'] }}</td>
                <td class="num">{{ InvoicePdf::money($line['unit_price_ttc_xof']) }}</td>
                <td class="num">{{ InvoicePdf::money($line['amount_ttc_xof']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top:10px">
    <tr>
        <td style="width:55%; vertical-align:top" class="small">
            @if ($invoice->legal_note)<div style="font-weight:bold">{{ $invoice->legal_note }}</div>@endif
            @if (! $credit)
                <div style="margin-top:6px">Facture acquittée{{ ! empty($b['paid_at']) ? ' — réglée le '.\Illuminate\Support\Carbon::parse($b['paid_at'])->timezone($tz)->format('d/m/Y') : '' }} — {{ $methods[$b['payment_method'] ?? ''] ?? ($b['payment_method'] ?? '') }}.</div>
                @if (! empty($b['delivered_at']))<div>Livraison effectuée le {{ \Illuminate\Support\Carbon::parse($b['delivered_at'])->timezone($tz)->format('d/m/Y') }}.</div>@endif
            @else
                <div style="margin-top:6px">Montant remboursé au client.</div>
            @endif
        </td>
        <td style="width:45%">
            <table class="totals">
                @if ($invoice->vat_applicable)
                    <tr><td>Total HT</td><td class="num">{{ ($credit ? '−' : '').InvoicePdf::money($invoice->total_ht_xof) }}</td></tr>
                    <tr><td>TVA {{ InvoicePdf::rate($invoice->vat_rate_bp) }}</td><td class="num">{{ ($credit ? '−' : '').InvoicePdf::money($invoice->total_vat_xof) }}</td></tr>
                    <tr class="grand"><td>Total TTC</td><td class="num">{{ ($credit ? '−' : '').InvoicePdf::money($invoice->total_ttc_xof) }}</td></tr>
                @else
                    <tr class="grand"><td>Total</td><td class="num">{{ ($credit ? '−' : '').InvoicePdf::money($invoice->total_ttc_xof) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if (! empty($s['footer']))
    <div class="small" style="margin-top:18px">{{ $s['footer'] }}</div>
@endif

<footer>{{ $s['name'] ?? 'MK Délices' }}{{ ! empty($s['legal_name']) ? ' — '.$s['legal_name'] : '' }}{{ ! empty($s['ninea']) ? ' — NINEA '.$s['ninea'] : '' }} — {{ $invoice->label() }} {{ $invoice->number }}</footer>
</body>
</html>
