<?php

namespace Modules\HR\Http\Lists;

use App\Core\Exports\ExportValues;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Http\Resources\PunchResource;

/** HR-22, HR-35: punches, newest first. */
class PunchList extends HrList
{
    public function name(): string
    {
        return 'hr-punches';
    }

    public function auditAction(): string
    {
        return 'hr.punch.export';
    }

    public function title(array $filters): string
    {
        return __('hr.lists.punches');
    }

    public function resource(Model $model): JsonResource
    {
        return PunchResource::make($model);
    }

    public function sorts(): array
    {
        return ['occurred_at' => ListSort::column('occurred_at')];
    }

    public function defaultSort(): string
    {
        return '-occurred_at';
    }

    public function columns(): array
    {
        return [
            ListColumn::make('occurred_at', 'hr.columns.occurred_at', ['occurred_at'], fn (array $row, $model, ExportValues $values) => $values->dateTime($row['occurred_at'] ?? null)),
            self::employee(),
            ListColumn::make('kind', 'hr.columns.kind', ['kind'], fn (array $row) => __('hr.punch_kinds.'.$row['kind'])),
            ListColumn::make('source', 'hr.columns.source', ['source'], fn (array $row) => __('hr.punch_sources.'.$row['source'])),
            self::text('note'),
        ];
    }

    public function exportRelations(): array
    {
        return ['employee'];
    }
}
