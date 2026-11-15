<?php

namespace App\Domain\Orders;

use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Créneaux de livraison calculés depuis les horaires paramétrés (fuseau Africa/Dakar),
 * le délai minimal, le temps de préparation et la capacité par créneau (BR-004).
 */
class SlotService
{
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return list<array{start_at:string, end_at:string, remaining:int, label:string}>
     */
    public function available(int $leadMinutes, int $limit = 40, ?string $excludeOrderId = null): array
    {
        $tz = $this->settings->timezone();
        $duration = (int) $this->settings->get('ordering.slot_duration_minutes', 120);
        $capacity = (int) $this->settings->get('ordering.slot_capacity', 5);
        $hours = (array) $this->settings->get('business.hours', []);
        $closed = (array) $this->settings->get('ordering.closed_dates', []);
        $earliest = CarbonImmutable::now($tz)->addMinutes($leadMinutes);
        $days = (int) $this->settings->get('ordering.max_days_ahead', 14);

        $candidates = [];
        for ($d = 0; $d <= $days && count($candidates) < $limit; $d++) {
            $day = CarbonImmutable::now($tz)->startOfDay()->addDays($d);
            if (in_array($day->toDateString(), $closed, true)) {
                continue;
            }
            foreach ($hours[self::DAYS[$day->dayOfWeek]] ?? [] as [$open, $close]) {
                $start = $day->setTimeFromTimeString($open);
                $end = $day->setTimeFromTimeString($close);
                for ($s = $start; $s->addMinutes($duration)->lte($end); $s = $s->addMinutes($duration)) {
                    if ($s->gte($earliest)) {
                        $candidates[] = $s;
                    }
                }
            }
        }
        $candidates = array_slice($candidates, 0, $limit);
        if ($candidates === []) {
            return [];
        }

        $counts = $this->countsFor($candidates, $excludeOrderId);
        $slots = [];
        foreach ($candidates as $start) {
            $utc = $start->utc();
            $remaining = $capacity - ($counts[$utc->toIso8601ZuluString()] ?? 0);
            if ($remaining > 0) {
                $slots[] = [
                    'start_at' => $utc->toIso8601ZuluString(),
                    'end_at' => $utc->addMinutes($duration)->toIso8601ZuluString(),
                    'remaining' => $remaining,
                    'label' => $start->locale('fr')->isoFormat('dddd D MMMM').' · '.$start->format('H\hi').'–'.$start->addMinutes($duration)->format('H\hi'),
                ];
            }
        }

        return $slots;
    }

    /**
     * Vérifie un créneau et verrouille sa capacité dans la transaction courante.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function reserve(string $slotStartIso, int $leadMinutes, ?string $excludeOrderId = null): array
    {
        try {
            $requested = CarbonImmutable::parse($slotStartIso)->utc();
        } catch (\Throwable) {
            throw DomainException::unprocessable('slot_invalid', 'Créneau invalide.');
        }
        $key = $requested->toIso8601ZuluString();
        // Verrou transactionnel par créneau : deux validations concurrentes sont sérialisées (T-023).
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["slot:{$key}"]);

        foreach ($this->available($leadMinutes, 500, $excludeOrderId) as $slot) {
            if ($slot['start_at'] === $key) {
                return ['start' => $requested, 'end' => CarbonImmutable::parse($slot['end_at'])];
            }
        }
        $next = $this->available($leadMinutes, 60, $excludeOrderId)[0] ?? null;

        throw DomainException::conflict('slot_unavailable', 'Ce créneau n\'est plus disponible.', ['next_slot' => $next]);
    }

    /** @param list<CarbonImmutable> $starts */
    private function countsFor(array $starts, ?string $excludeOrderId): array
    {
        $utcStarts = array_map(fn (CarbonImmutable $s) => $s->utc(), $starts);
        $rows = Order::query()
            ->whereIn('status', OrderStatus::occupyingSlot())
            ->whereBetween('slot_start_at', [min($utcStarts), max($utcStarts)])
            ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->selectRaw('slot_start_at, count(*) as c')
            ->groupBy('slot_start_at')
            ->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[CarbonImmutable::parse($row->slot_start_at)->utc()->toIso8601ZuluString()] = (int) $row->c;
        }

        return $counts;
    }
}
