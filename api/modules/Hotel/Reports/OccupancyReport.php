<?php

namespace Modules\Hotel\Reports;

use Carbon\CarbonImmutable;
use Modules\Hotel\Availability\Availability;
use Modules\Hotel\Models\NightAuditRun;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Support\Properties;

/**
 * HOT-31: occupancy, ADR and RevPAR per day for a property.
 *
 * - Days already audited use the night audit's snapshot (`source: audit`):
 *   the rooms available and sold and the room revenue of that night as it was.
 * - The business date and later days are live (`source: live` for today,
 *   `forecast` for the future): rooms available now, rooms held by stays,
 *   revenue from the stays' quoted nights.
 * ADR is net room revenue over rooms sold; RevPAR is net room revenue over
 * rooms available (rooms out of order are not available). Revenue is per
 * currency; a total across currencies is never made.
 */
class OccupancyReport
{
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly Properties $properties,
        private readonly Availability $availability,
    ) {}

    /** @return array{branch_id: string, from: string, to: string, business_date: string, days: list<array<string, mixed>>, totals: array<string, mixed>} */
    public function between(string $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $today = $this->properties->businessDate($branchId);
        $audits = NightAuditRun::query()->where('branch_id', $branchId)->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])->get()->keyBy(fn ($a) => $a->business_date->toDateString());
        $rooms = Room::query()->where('branch_id', $branchId)->whereNull('archived_at')->get();
        $stays = Stay::query()->where('branch_id', $branchId)->whereIn('status', [Stay::RESERVED, Stay::CHECKED_IN, Stay::CHECKED_OUT])
            ->where('arrival_on', '<=', $to->toDateString())->where('departure_on', '>', $from->toDateString())->get(['id', 'arrival_on', 'departure_on', 'nightly', 'currency', 'status']);
        $days = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $audit = $audits->get($date);

            if ($audit !== null) {
                $summary = $audit->summary;
                $days[] = $this->row($date, 'audit', (int) $summary['rooms_available'], (int) $summary['rooms_sold'], (array) ($summary['revenue'] ?? []));

                continue;
            }

            $available = $rooms->count() - $rooms->filter(fn (Room $r) => $this->availability->outOfOrderOn($r, $date))->count();
            $held = $stays->filter(fn (Stay $s) => $s->arrival_on->toDateString() <= $date && $s->departure_on->toDateString() > $date);
            $revenue = [];

            foreach ($held as $stay) {
                $night = collect((array) $stay->nightly)->firstWhere('date', $date);

                if ($night !== null) {
                    $revenue[$stay->currency]['net_minor'] = ($revenue[$stay->currency]['net_minor'] ?? 0) + (int) $night['amount_minor'];
                }
            }

            $days[] = $this->row($date, $date === $today ? 'live' : 'forecast', $available, $held->count(), $revenue);
        }

        return ['branch_id' => $branchId, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'business_date' => $today, 'days' => $days, 'totals' => $this->totals($days)];
    }

    /** @param array<string, array<string, int>> $revenue */
    private function row(string $date, string $source, int $available, int $sold, array $revenue): array
    {
        $by = [];

        foreach ($revenue as $currency => $r) {
            $net = (int) ($r['net_minor'] ?? 0);
            $by[$currency] = ['net_minor' => $net, 'adr_minor' => $sold > 0 ? (int) round($net / $sold) : 0, 'revpar_minor' => $available > 0 ? (int) round($net / $available) : 0];
        }

        return [
            'date' => $date, 'source' => $source, 'rooms_available' => $available, 'rooms_sold' => $sold,
            'occupancy_percent' => $available > 0 ? round(100 * $sold / $available, 1) : 0.0, 'revenue' => $by,
        ];
    }

    /** @param list<array<string, mixed>> $days */
    private function totals(array $days): array
    {
        $available = array_sum(array_column($days, 'rooms_available'));
        $sold = array_sum(array_column($days, 'rooms_sold'));
        $net = [];

        foreach ($days as $day) {
            foreach ($day['revenue'] as $currency => $r) {
                $net[$currency] = ($net[$currency] ?? 0) + $r['net_minor'];
            }
        }

        return [
            'rooms_available' => $available, 'rooms_sold' => $sold, 'occupancy_percent' => $available > 0 ? round(100 * $sold / $available, 1) : 0.0,
            'revenue' => collect($net)->map(fn (int $total) => [
                'net_minor' => $total, 'adr_minor' => $sold > 0 ? (int) round($total / $sold) : 0, 'revpar_minor' => $available > 0 ? (int) round($total / $available) : 0,
            ])->all(),
        ];
    }
}
