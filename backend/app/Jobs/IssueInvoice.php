<?php

namespace App\Jobs;

use App\Domain\Invoicing\InvoiceService;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Émission automatique (asynchrone, idempotente) : facture d'une commande ou avoir d'un remboursement. */
class IssueInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $orderId, public readonly ?string $refundId = null) {}

    public function backoff(): array
    {
        return [10, 60, 300, 1800];
    }

    public function handle(InvoiceService $invoices): void
    {
        if ($this->refundId) {
            if ($refund = Refund::query()->find($this->refundId)) {
                $invoices->issueCreditNoteForRefund($refund, null);
            }

            return;
        }
        if ($order = Order::query()->find($this->orderId)) {
            $invoices->issueIfDue($order);
        }
    }
}
