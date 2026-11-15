<?php

namespace App\Domain\Invoicing;

use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Notifications\CustomerInvoiceIssued;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Envoi de la facture / de l'avoir au client (PDF joint), une seule fois par document. */
class InvoiceNotifier
{
    public function issued(Invoice $invoice): void
    {
        $order = $invoice->order;
        $email = $order->customer_snapshot['email'] ?? null;
        $inserted = NotificationLog::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'recipient_masked' => $email ? preg_replace('/(?<=.).(?=[^@]*@)/', '*', $email) : null,
            'event' => "customer:invoice.{$invoice->type}",
            'channel' => $email ? 'mail' : 'none',
            'dedupe_key' => "invoice:{$invoice->id}",
            'delivery_status' => $email ? 'sent' : 'skipped',
            'sent_at' => $email ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 1 && $email) {
            Notification::route('mail', $email)->notify(new CustomerInvoiceIssued($invoice));
        }
    }
}
