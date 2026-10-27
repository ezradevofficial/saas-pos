<?php

namespace Modules\Salon\Availability;

use App\Core\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;

/**
 * SAL-06, SAL-07, SAL-08: plans the lines of an appointment, one after another from a start time, with the staff
 * member and resource of each, against what is already taken. Used to list open times (data read without locks) and
 * to book (data read again under the staff and resource row locks), so both apply the same rules.
 *
 * A plan is a list of lines `{service, staff, resource, starts_at, ends_at, blocked_until}`, or a string: the code of
 * the first reason it cannot be had (`staff_unqualified`, `outside_hours`, `staff_off`, `slot_taken`, `no_staff`,
 * `no_resource`, `resource_taken`).
 */
class Planner
{
    public function __construct(private readonly StaffCalendar $calendar) {}

    /**
     * Everything a plan reads, for a window of time.
     *
     * @param  Collection<int, Staff>  $staff  the staff that may be picked
     * @param  Collection<int, resource>  $resources
     */
    public function context(Branch $branch, Collection $staff, Collection $resources, CarbonImmutable $from, CarbonImmutable $to, ?string $ignoreAppointment = null): Context
    {
        $busy = Line::query()->where('holds', true)->where('starts_at', '<', $to)->where('blocked_until', '>', $from)
            ->where(fn ($q) => $q->whereIn('staff_id', $staff->modelKeys())->orWhereIn('resource_id', $resources->modelKeys()))
            ->when($ignoreAppointment !== null, fn ($q) => $q->where('appointment_id', '<>', $ignoreAppointment))
            ->get(['staff_id', 'resource_id', 'starts_at', 'blocked_until']);

        return new Context($branch, $staff->keyBy('id'), $resources->keyBy('id'), $this->calendar->spans($staff, $branch, $from, $to), $busy);
    }

    /**
     * @param  list<array{service: Service, staff_id?: ?string, resource_id?: ?string}>  $wants
     * @return list<array{service: Service, staff: Staff, resource: ?resource, starts_at: CarbonImmutable, ends_at: CarbonImmutable, blocked_until: CarbonImmutable}>|string
     */
    public function plan(Context $ctx, array $wants, CarbonImmutable $start, bool $checkHours = true): array|string
    {
        $taken = [];
        $lines = [];
        $cursor = $start;
        $previous = null;

        foreach ($wants as $want) {
            /** @var Service $service */
            $service = $want['service'];

            if ($previous !== null) {
                // The next service starts when the last ends; the same staff member or resource first needs its clean-up time.
                $cursor = $previous['ends_at'];
            }

            $result = $this->line($ctx, $service, $want['staff_id'] ?? null, $want['resource_id'] ?? null, $cursor, $checkHours, $taken, $previous);

            if (is_string($result)) {
                return $result;
            }

            $taken[] = $result;
            $lines[] = $result;
            $previous = $result;
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $taken lines already planned in this appointment */
    private function line(Context $ctx, Service $service, ?string $staffId, ?string $resourceId, CarbonImmutable $cursor, bool $checkHours, array $taken, ?array $previous): array|string
    {
        $candidates = $staffId !== null ? array_filter([$ctx->staff->get($staffId)]) : $this->qualified($ctx, $service);

        if ($candidates === []) {
            return $staffId !== null ? 'staff_unqualified' : 'no_staff';
        }

        $first = null;

        // Fewest bookings first when anyone will do, then by name.
        $candidates = collect($candidates)->sortBy([fn (Staff $a, Staff $b) => $ctx->bookings($a->id) <=> $ctx->bookings($b->id), ['name', 'asc'], ['id', 'asc']])->values();

        foreach ($candidates as $member) {
            /** @var Staff $member */
            if ($service->staff_ids !== [] && ! in_array($member->id, $service->staff_ids, true)) {
                $first ??= 'staff_unqualified';

                continue;
            }

            // A member who just finished the previous line first cleans up.
            $from = $previous !== null && $previous['staff']->id === $member->id ? $previous['blocked_until'] : $cursor;
            $end = $from->addMinutes($service->duration_minutes);
            $blocked = $end->addMinutes($service->buffer_minutes);
            $spans = $ctx->spans[$member->id] ?? ['work' => [], 'off' => []];

            if ($checkHours) {
                if (! StaffCalendar::onDuty($spans, $from, $end)) {
                    $first ??= 'outside_hours';

                    continue;
                }

                if (StaffCalendar::off($spans, $from, $end)) {
                    $first ??= 'staff_off';

                    continue;
                }
            }

            if ($ctx->staffBusy($member->id, $from, $blocked) || $this->overlapsTaken($taken, 'staff', $member->id, $from, $blocked)) {
                $first ??= 'slot_taken';

                continue;
            }

            $resource = $this->resource($ctx, $service, $resourceId, $from, $blocked, $taken);

            if (is_string($resource)) {
                $first ??= $resource;

                continue;
            }

            return ['service' => $service, 'staff' => $member, 'resource' => $resource, 'starts_at' => $from, 'ends_at' => $end, 'blocked_until' => $blocked];
        }

        return $first ?? 'no_staff';
    }

    /** @return list<Staff> */
    private function qualified(Context $ctx, Service $service): array
    {
        return $ctx->staff->filter(fn (Staff $member) => $service->staff_ids === [] || in_array($member->id, $service->staff_ids, true))->values()->all();
    }

    private function resource(Context $ctx, Service $service, ?string $wanted, CarbonImmutable $from, CarbonImmutable $to, array $taken): Resource|string|null
    {
        if ($service->resource_ids === [] && $wanted === null) {
            return null;
        }

        $allowed = $service->resource_ids === [] ? $ctx->resources->keys()->all() : $service->resource_ids;
        $pool = $wanted !== null ? [$wanted] : $allowed;

        if ($wanted !== null && ! in_array($wanted, $allowed, true)) {
            return 'no_resource';
        }

        $seen = false;

        foreach ($pool as $id) {
            $resource = $ctx->resources->get($id);

            if ($resource === null) {
                continue;
            }

            $seen = true;

            if (! $ctx->resourceBusy($resource->id, $from, $to) && ! $this->overlapsTaken($taken, 'resource', $resource->id, $from, $to)) {
                return $resource;
            }
        }

        return $seen ? 'resource_taken' : 'no_resource';
    }

    /** @param list<array<string, mixed>> $taken */
    private function overlapsTaken(array $taken, string $by, string $id, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        foreach ($taken as $line) {
            $own = $by === 'staff' ? $line['staff']->id : $line['resource']?->id;

            if ($own === $id && $from->lessThan($line['blocked_until']) && $to->greaterThan($line['starts_at'])) {
                return true;
            }
        }

        return false;
    }
}
