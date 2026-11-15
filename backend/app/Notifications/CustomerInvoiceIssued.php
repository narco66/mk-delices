<?php

namespace App\Notifications;

use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Shared\Money;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerInvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Invoice $invoice)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice->loadMissing('order', 'originalInvoice');
        $label = $invoice->label();

        return (new MailMessage)
            ->subject("MK Délices — {$label} {$invoice->number} (commande {$invoice->order->reference})")
            ->greeting('Bonjour '.($invoice->order->customer_snapshot['first_name'] ?? '').',')
            ->line($invoice->isCreditNote()
                ? "Vous trouverez ci-joint l'avoir {$invoice->number} correspondant à votre remboursement."
                : "Merci pour votre commande ! Vous trouverez ci-joint votre facture {$invoice->number}.")
            ->line('Montant : '.Money::format($invoice->total_ttc_xof))
            ->line('Le document reste disponible depuis votre lien de suivi de commande.')
            ->attachData(app(InvoicePdf::class)->render($invoice), $invoice->fileName(), ['mime' => 'application/pdf'])
            ->salutation('L\'équipe MK Délices');
    }
}
