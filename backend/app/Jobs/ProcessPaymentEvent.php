<?php

namespace App\Jobs;

use App\Domain\Payments\PaymentService;
use App\Models\PaymentEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPaymentEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly string $paymentEventId) {}

    /** Reprises exponentielles si le prestataire est momentanément injoignable. */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function handle(PaymentService $payments): void
    {
        $event = PaymentEvent::query()->with('payment')->find($this->paymentEventId);
        if ($event) {
            $payments->processEvent($event);
        }
    }
}
