<?php

namespace Modules\HR\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Http\Resources\RosterShiftResource;

/** HR-18, HR-35: planned shifts by date. */
class RosterShiftList extends HrList
{
    public const RELATIONS = ['employee', 'shift'];

    public function name(): string
    {
        return 'hr-roster-shifts';
    }

    public function auditAction(): string
    {
        return 'hr.roster_shift.export';
    }

    public function title(array $filters): string
    {
        return __('hr.lists.roster_shifts');
    }

    public function resource(Model $model): JsonResource
    {
        return RosterShiftResource::make($model);
    }

    public function sorts(): array
    {
        return ['date' => ListSort::column('date'), 'start_at' => ListSort::column('start_at')];
    }

    public function defaultSort(): string
    {
        return 'date';
    }

    public function columns(): array
    {
        return [
            self::date('date'),
            self::employee(),
            ListColumn::make('shift', 'hr.columns.shift', ['shift'], fn (array $row) => $row['shift']['name'] ?? null),
            ListColumn::make('start_at', 'hr.columns.start_at', ['start_at'], fn (array $row, $model, ExportValues $values) => $values->dateTime($row['start_at'] ?? null)),
            ListColumn::make('end_at', 'hr.columns.end_at', ['end_at'], fn (array $row, $model, ExportValues $values) => $values->dateTime($row['end_at'] ?? null)),
            self::status(),
        ];
    }

    public function exportRelations(): array
    {
        return self::RELATIONS;
    }
}
