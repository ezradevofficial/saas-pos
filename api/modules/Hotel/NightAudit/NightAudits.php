<?php

namespace Modules\Hotel\NightAudit;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Availability\Availability;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\Housekeeping\Housekeeping;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\NightAuditRun;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Reservations\Reservations;
use Modules\Hotel\Support\HotelError;
use Modules\Hotel\Support\Properties;
use Modules\Hotel\Support\Text;
use Throwable;

/**
 * HOT-30: the night audit of one property for its business date, in one
 * transaction under the property's row lock:
 *
 * 1. posts the night's room charge to every in-house stay (its quoted rate
 *    for the night, tax and levies from the company's tax codes), once per
 *    stay and night (the line's source key), so a repeat or a concurrent run
 *    changes nothing; a guest still in the room past their departure is
 *    charged at the last rate and listed as an overstay;
 * 2. marks guests who did not arrive as no-shows and charges them as the
 *    company's rules say (Reservations::noShow, HOT-15);
 * 3. creates the next day's stay-over and clean tasks;
 * 4. saves the occupancy snapshot (rooms available and sold, arrivals,
 *    departures, room revenue, occupancy, ADR, RevPAR);
 * 5. rolls the business date forward.
 *
 * Idempotent per branch and business date: the audit of a date already
 * done is returned as it was (`created` false). Only the current business
 * date can be audited; one that is not over cannot be closed early by the
 * schedule (a manual run by `hotel.audit.run` may).
 */
class NightAudits
{
    public function __construct(
        private readonly Properties $properties,
        private readonly Folios $folios,
        private readonly Reservations $reservations,
        private readonly Housekeeping $housekeeping,
        private readonly Availability $availability,
        private readonly Auditor $auditor,
    ) {}

    /** @return array{run: NightAuditRun, created: bool} */
    public function run(string $branchId, ?User $by = null, ?string $businessDate = null): array
    {
        $branch = $this->properties->require($branchId);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($branch, $by, $businessDate) {
            $property = $this->properties->lock($branch->id);
            $current = $property->business_date->toDateString();
            $date = $businessDate ?? $current;

            $existing = NightAuditRun::query()->where('branch_id', $branch->id)->where('business_date', $date)->first();

            if ($existing !== null) {
                return ['run' => $existing, 'created' => false];
            }

            if ($date > $current) {
                throw HotelError::make(409, 'audit_date_future', field: 'business_date');
            }

            if ($date < $current) {
                // A past date without an audit (the property was set up after it): nothing to close.
                throw HotelError::make(409, 'audit_date_past', field: 'business_date');
            }

            $started = now();
            $settings = Settings::for($branch->company_id);
            $stats = ['rooms_sold' => 0, 'comp' => 0, 'posted' => 0, 'overstays' => [], 'flags' => [], 'errors' => []];
            $this->postNights($branch->id, $date, $settings, $stats);
            $stats['no_shows'] = $this->noShows($branch->id, $date, $stats);
            $stats['tasks'] = $this->tasks($branch->id, $date);
            $summary = [...$this->snapshot($branch->id, $branch->company_id, $date, $stats)];

            $run = NightAuditRun::create([
                'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'business_date' => $date, 'status' => 'completed',
                'started_at' => $started, 'completed_at' => now(), 'run_by' => $by?->id, 'summary' => $summary,
            ]);
            $property->forceFill(['business_date' => CarbonImmutable::parse($date)->addDay()->toDateString(), 'rolled_at' => now()])->save();
            $this->auditor->record('hotel.night_audit.run', $run, null, [
                'branch_id' => $branch->id, 'business_date' => $date, 'rooms_sold' => $stats['rooms_sold'], 'no_shows' => $stats['no_shows'], 'manual' => $by !== null,
            ]);

            return ['run' => $run, 'created' => true];
        });
    }

    /** Whether the audit of $branch's current business date is due now (its audit time has passed on the day after it). */
    public function due(string $branchId, ?CarbonImmutable $now = null): bool
    {
        $branch = $this->properties->branch($branchId);

        if ($branch === null || $branch->archived_at !== null) {
            return false;
        }

        $date = $this->properties->businessDate($branchId);
        $time = Settings::for($branch->company_id)->night_audit_time;
        $zone = CompanyClock::timezone($branch->company_id, $branchId);
        $dueAt = CarbonImmutable::parse(CarbonImmutable::parse($date)->addDay()->toDateString().' '.$time.':00', $zone);

        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($dueAt);
    }

    /** @param array<string, mixed> $stats */
    private function postNights(string $branchId, string $date, Settings $settings, array &$stats): void
    {
        $stays = Stay::query()->where('branch_id', $branchId)->where('status', Stay::CHECKED_IN)->where('arrival_on', '<=', $date)->orderBy('arrival_on')->orderBy('id')->get();
        $rooms = Room::query()->whereKey($stays->pluck('room_id')->filter()->all())->pluck('number', 'id');

        foreach ($stays as $stay) {
            $nights = collect((array) $stay->nightly)->keyBy('date');
            $night = $nights->get($date) ?? $nights->last();

            if ($night === null) {
                continue;
            }

            $overstay = $stay->departure_on->toDateString() <= $date;

            if ($overstay) {
                $stats['overstays'][] = ['stay_id' => $stay->id, 'room_number' => $rooms[$stay->room_id] ?? null, 'departure_on' => $stay->departure_on->toDateString()];
            }

            $stats['rooms_sold']++;

            if ((int) $night['amount_minor'] === 0) {
                $stats['comp']++;

                continue;
            }

            $plan = RatePlan::query()->find($stay->rate_plan_id);
            $line = $this->folios->charge($this->folios->billingFolio($stay), [
                'kind' => FolioLine::ROOM,
                'item_id' => $settings->room_item_id,
                'description' => Text::line('hotel.lines.room_night', ['room' => $rooms[$stay->room_id] ?? '', 'date' => $date]),
                'qty' => '1',
                'unit_price_minor' => (int) $night['amount_minor'],
                'tax_inclusive' => (bool) ($plan?->tax_inclusive ?? false),
                'business_date' => $date,
                'source' => 'night_audit',
                'source_id' => "{$stay->id}:{$date}",
                'levies' => true,
                'flags' => $overstay ? ['overstay'] : [],
            ], null, audit: false);

            if ($line->wasRecentlyCreated) {
                $stats['posted']++;
                $stats['flags'] = array_values(array_unique([...$stats['flags'], ...$line->flags]));
            }
        }
    }

    /** @param array<string, mixed> $stats */
    private function noShows(string $branchId, string $date, array &$stats): int
    {
        $count = 0;

        foreach (Stay::query()->where('branch_id', $branchId)->where('status', Stay::RESERVED)->where('arrival_on', '<=', $date)->orderBy('arrival_on')->get() as $stay) {
            try {
                $this->reservations->noShow($stay, null, false, null);
                $count++;
            } catch (Throwable $e) {
                report($e);
                $stats['errors'][] = ['stay_id' => $stay->id, 'error' => $e instanceof ApiException ? $e->errorCode : 'failed'];
            }
        }

        return $count;
    }

    /** Tomorrow's work: a clean for every room left dirty, a stay-over clean for guests staying beyond tomorrow. */
    private function tasks(string $branchId, string $date): int
    {
        $next = CarbonImmutable::parse($date)->addDay()->toDateString();
        $made = 0;
        $stayovers = Stay::query()->where('branch_id', $branchId)->where('status', Stay::CHECKED_IN)->where('departure_on', '>', $next)->whereNotNull('room_id')->pluck('room_id');

        foreach (Room::query()->where('branch_id', $branchId)->whereNull('archived_at')->whereIn('status', [Room::VACANT_DIRTY, Room::OCCUPIED])->get() as $room) {
            if ($room->status === Room::VACANT_DIRTY) {
                $this->housekeeping->ensureTask($room, 'checkout_clean', $next);
                $made++;
            } elseif ($stayovers->contains($room->id)) {
                $this->housekeeping->ensureTask($room, 'stayover', $next);
                $made++;
            }
        }

        return $made;
    }

    /**
     * The occupancy snapshot of the audited date (HOT-30, HOT-31). Room revenue is the net of the date's room lines, per currency (a
     * line voided later is not netted back: the snapshot is what the night was).
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function snapshot(string $branchId, string $companyId, string $date, array $stats): array
    {
        $rooms = Room::query()->where('branch_id', $branchId)->whereNull('archived_at')->get();
        $out = $rooms->filter(fn (Room $r) => $this->availability->outOfOrderOn($r, $date))->count();
        $available = $rooms->count() - $out;
        $revenue = [];

        foreach (FolioLine::query()->where('kind', FolioLine::ROOM)->where('business_date', $date)->whereIn('folio_id', fn ($q) => $q->select('id')->from('hotel_folios')->where('branch_id', $branchId))
            ->selectRaw('currency, sum(net_minor) as net, sum(amount_minor) as gross, sum(tax_minor) as tax')->groupBy('currency')->get() as $row) {
            $sold = max(1, (int) $stats['rooms_sold'] - (int) $stats['comp']);
            $revenue[$row->currency] = [
                'net_minor' => (int) $row->net, 'gross_minor' => (int) $row->gross, 'tax_minor' => (int) $row->tax,
                'adr_minor' => (int) round($row->net / $sold),
                'revpar_minor' => $available > 0 ? (int) round($row->net / $available) : 0,
            ];
        }

        $arrivals = Stay::query()->where('branch_id', $branchId)->where('arrival_on', $date)->whereIn('status', [Stay::CHECKED_IN, Stay::CHECKED_OUT])->count();
        $departures = Stay::query()->where('branch_id', $branchId)->where('departure_on', $date)->where('status', Stay::CHECKED_OUT)->count();
        $guests = Stay::query()->where('branch_id', $branchId)->where('status', Stay::CHECKED_IN)->selectRaw('coalesce(sum(adults + children), 0) as total')->value('total');

        return [
            'rooms_total' => $rooms->count(), 'rooms_out_of_order' => $out, 'rooms_available' => $available, 'rooms_sold' => $stats['rooms_sold'], 'rooms_complimentary' => $stats['comp'],
            'occupancy_percent' => $available > 0 ? round(100 * $stats['rooms_sold'] / $available, 1) : 0.0,
            'arrivals' => $arrivals, 'departures' => $departures, 'no_shows' => $stats['no_shows'], 'in_house_guests' => (int) $guests,
            'charges_posted' => $stats['posted'], 'tasks_created' => $stats['tasks'], 'overstays' => $stats['overstays'], 'flags' => $stats['flags'], 'errors' => $stats['errors'],
            'revenue' => $revenue,
        ];
    }
}
