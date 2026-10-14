<?php

namespace Modules\HR\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Http\Resources\AttendanceDayResource;
use Modules\HR\Models\AttendanceDay;

/** HR-25, HR-35: timesheet days. */
class TimesheetList extends HrList
{
    public function name(): string
    {
        return 'hr-timesheets';
    }

    public function auditAction(): string
    {
        return 'hr.timesheet.export';
    }

    public function title(array $filters): string
    {
        return __('hr.lists.timesheets');
    }

    public function resource(Model $model): JsonResource
    {
        return AttendanceDayResource::make($model);
    }

    public function sorts(): array
    {
        return ['work_date' => ListSort::column('work_date')];
    }

    public function defaultSort(): string
    {
        return 'work_date';
    }

    public function columns(): array
    {
        $columns = [self::date('work_date'), self::employee(), self::status(),
            ListColumn::make('exceptions', 'hr.columns.exceptions', ['exceptions'], fn (array $row) => implode(', ', array_map(fn ($e) => __('hr.exceptions.'.$e), $row['exceptions'] ?? [])))];

        foreach (AttendanceDay::MINUTES as $minutes) {
            $columns[] = self::text("{$minutes}_minutes");
        }

        return $columns;
    }

    public function exportRelations(): array
    {
        return ['employee'];
    }
}
