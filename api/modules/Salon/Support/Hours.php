<?php

namespace Modules\Salon\Support;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\StaffHour;

/**
 * SAL-04: a staff member's weekly work and break spans at the branches they work at. The list is replaced whole (like
 * route stops): what is left out is removed, and the change is audited as one event with its count, per branch. Spans of
 * one kind may not overlap on a day; a break must sit inside a work span of that day.
 */
class Hours
{
    public function __construct(private readonly Auditor $auditor, private readonly Places $places) {}

    /** @param list<array{branch_id: string, weekday: int|string, kind?: string, start: string, end: string}> $hours */
    public function replace(Staff $staff, array $hours): void
    {
        $rows = [];

        foreach ($hours as $index => $row) {
            $branch = $this->places->openBranch((string) $row['branch_id'], "hours.{$index}.branch_id");

            if ($branch->company_id !== $staff->company_id || ($staff->branch_id !== null && $staff->branch_id !== $branch->id)) {
                throw Errors::make(422, 'branch_unknown', field: "hours.{$index}.branch_id");
            }

            $rows[] = ['branch_id' => $branch->id, 'weekday' => (int) $row['weekday'], 'kind' => $row['kind'] ?? 'work', 'start' => substr($row['start'], 0, 5), 'end' => substr($row['end'], 0, 5)];
        }

        $this->assertShape($rows);

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($staff, $rows) {
            Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $before = StaffHour::query()->where('staff_id', $staff->id)->count();
            StaffHour::query()->where('staff_id', $staff->id)->delete();

            foreach ($rows as $row) {
                StaffHour::create(['staff_id' => $staff->id, 'branch_id' => $row['branch_id'], 'weekday' => $row['weekday'], 'kind' => $row['kind'], 'starts_at' => $row['start'], 'ends_at' => $row['end']]);
            }

            $this->auditor->record('salon.staff.hours', $staff, null, ['before' => $before, 'after' => count($rows)]);
        });
    }

    /** @param list<array{branch_id: string, weekday: int, kind: string, start: string, end: string}> $rows */
    private function assertShape(array $rows): void
    {
        $groups = collect($rows)->groupBy(fn (array $r) => $r['branch_id'].'|'.$r['weekday']);

        foreach ($groups as $day) {
            foreach (['work', 'break'] as $kind) {
                $spans = $day->where('kind', $kind)->sortBy('start')->values();

                foreach ($spans as $i => $span) {
                    if ($span['end'] <= $span['start']) {
                        throw Errors::make(422, 'hours_invalid', field: 'hours');
                    }

                    if ($i > 0 && $span['start'] < $spans[$i - 1]['end']) {
                        throw Errors::make(422, 'hours_overlap', field: 'hours');
                    }
                }
            }

            $work = $day->where('kind', 'work');

            foreach ($day->where('kind', 'break') as $break) {
                if (! $work->contains(fn (array $w) => $break['start'] >= $w['start'] && $break['end'] <= $w['end'])) {
                    throw Errors::make(422, 'break_outside_work', field: 'hours');
                }
            }
        }
    }
}
