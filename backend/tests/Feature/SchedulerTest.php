<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** Planificateur : aucune tâche critique ne peut rester bloquée par un verrou orphelin (audit E10). */
class SchedulerTest extends TestCase
{
    public function test_payment_reconciliation_lock_expires_quickly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains($e->command, 'mk:reconcile-payments'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThanOrEqual(10, $event->expiresAt, 'Verrou orphelin : rapprochement des paiements bloqué trop longtemps.');
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    public function test_failed_jobs_are_pruned(): void
    {
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn (Event $e) => str_contains($e->command, 'queue:prune-failed')));
    }
}
