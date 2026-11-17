<?php

namespace App\Jobs;

use App\Domain\Deliveries\AssignmentStatus;
use App\Domain\Deliveries\Push\CourierPushNotifier;
use App\Models\DeliveryAssignment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Notification du navigateur « nouvelle mission » au livreur (hors transaction, en file d'attente). */
class NotifyCourierOfMission implements ShouldQueue
{
    use Queueable;

    /** Une notification en retard de plusieurs minutes n'a plus d'intérêt : peu de nouvelles tentatives. */
    public int $tries = 2;

    public function __construct(public readonly string $assignmentId) {}

    public function backoff(): array
    {
        return [30];
    }

    public function handle(CourierPushNotifier $notifier): void
    {
        $mission = DeliveryAssignment::query()->find($this->assignmentId);
        // Mission retirée ou déjà traitée entre-temps : rien à signaler.
        if ($mission && $mission->status === AssignmentStatus::Proposed) {
            $notifier->newMission($mission);
        }
    }
}
