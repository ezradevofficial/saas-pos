<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Models\AppraisalCycle;
use Modules\HR\Models\AttendanceDevice;
use Modules\HR\Models\AttendancePeriod;
use Modules\HR\Models\ChecklistTemplate;
use Modules\HR\Models\Roster;
use Modules\HR\Models\Rotation;
use Modules\HR\Models\Shift;
use Modules\HR\Models\TimeRule;

/** HR-16, HR-17, HR-20, HR-24, HR-26, HR-28, HR-30: company setup rows, as stored (times HH:MM). */
class SetupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $base = ['id' => $row->id, 'company_id' => $row->company_id];

        return $base + match (true) {
            $row instanceof Shift => [
                'code' => $row->code, 'name' => $row->name, 'start_time' => substr((string) $row->start_time, 0, 5), 'end_time' => substr((string) $row->end_time, 0, 5),
                'break_minutes' => $row->break_minutes, 'archived_at' => Refs::at($row->archived_at),
            ],
            $row instanceof Rotation => ['name' => $row->name, 'cycle_days' => $row->cycle_days, 'days' => $row->days, 'archived_at' => Refs::at($row->archived_at)],
            $row instanceof Roster => [
                'name' => $row->name, 'branch' => Refs::named($row->branch), 'department' => Refs::coded($row->department), 'rotation_id' => $row->rotation_id,
                'anchor_date' => Refs::date($row->anchor_date), 'archived_at' => Refs::at($row->archived_at),
                'members' => $row->relationLoaded('members') ? $row->members->map(fn ($m) => [
                    'employee' => Refs::employee($m->employee), 'offset_days' => $m->offset_days, 'from_date' => Refs::date($m->from_date), 'to_date' => Refs::date($m->to_date),
                ])->all() : null,
            ],
            $row instanceof TimeRule => [
                'effective_from' => Refs::date($row->effective_from), 'effective_to' => Refs::date($row->effective_to),
                'daily_overtime_after_minutes' => $row->daily_overtime_after_minutes, 'weekly_overtime_after_minutes' => $row->weekly_overtime_after_minutes,
                'night_start' => $row->night_start === null ? null : substr((string) $row->night_start, 0, 5), 'night_end' => $row->night_end === null ? null : substr((string) $row->night_end, 0, 5),
                'late_grace_minutes' => $row->late_grace_minutes, 'early_leave_grace_minutes' => $row->early_leave_grace_minutes, 'rest_days' => $row->rest_days ?? [],
            ],
            $row instanceof AttendancePeriod => [
                'start_date' => Refs::date($row->start_date), 'end_date' => Refs::date($row->end_date), 'status' => $row->status,
                'closed_at' => Refs::at($row->closed_at), 'totals' => $row->totals,
            ],
            $row instanceof AttendanceDevice => [
                'location_id' => $row->location_id, 'name' => $row->name, 'vendor' => $row->vendor, 'status' => $row->status, 'last_seen_at' => Refs::at($row->last_seen_at),
            ],
            $row instanceof ChecklistTemplate => ['kind' => $row->kind, 'name' => $row->name, 'is_default' => $row->is_default, 'items' => $row->items, 'archived_at' => Refs::at($row->archived_at)],
            $row instanceof AppraisalCycle => ['name' => $row->name, 'period_start' => Refs::date($row->period_start), 'period_end' => Refs::date($row->period_end), 'status' => $row->status],
            default => [],
        };
    }
}
