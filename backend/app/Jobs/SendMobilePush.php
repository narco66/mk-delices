<?php

namespace App\Jobs;

use App\Domain\Customers\Push\MobilePushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Envoi d'une notification push mobile hors de la requête (l'appel à Firebase ne ralentit pas le métier). */
class SendMobilePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array{title:string, body:string, data:array<string,string>} $message */
    public function __construct(public readonly string $userId, public readonly array $message) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MobilePushNotifier $notifier): void
    {
        $notifier->deliver($this->userId, $this->message);
    }
}
