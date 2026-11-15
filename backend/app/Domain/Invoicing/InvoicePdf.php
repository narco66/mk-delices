<?php

namespace App\Domain\Invoicing;

use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/** Rendu PDF d'une facture ou d'un avoir, reconstruit à l'identique à partir du document immuable. */
class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $cache = storage_path('app/dompdf');
        File::ensureDirectoryExists($cache);

        $options = new Options;
        $options->setDefaultFont('DejaVu Sans'); // accents et symboles français
        $options->setIsRemoteEnabled(false);    // aucune ressource externe
        $options->setIsPhpEnabled(false);
        $options->setFontCache($cache);
        $options->setTempDir($cache);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('invoices.pdf', ['invoice' => $invoice])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Montant pour le PDF : espaces insécables simples (police PDF). */
    public static function money(int $amount): string
    {
        return ($amount < 0 ? '−' : '').number_format(abs($amount), 0, ',', "\u{00A0}")."\u{00A0}FCFA";
    }

    public static function rate(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',')."\u{00A0}%";
    }
}
