<?php

namespace Modules\HR\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Http\Resources\RecordResource;

/** HR-29, HR-35: disciplinary cases (only for holders of `hr.disciplinary.view`). */
class DisciplinaryList extends HrList
{
    public function name(): string
    {
        return 'hr-disciplinary-cases';
    }

    public function auditAction(): string
    {
        return 'hr.disciplinary_case.export';
    }

    public function title(array $filters): string
    {
        return __('hr.lists.disciplinary_cases');
    }

    public function resource(Model $model): JsonResource
    {
        return RecordResource::make($model);
    }

    public function sorts(): array
    {
        return ['incident_date' => ListSort::column('incident_date'), 'number' => ListSort::column('number')];
    }

    public function defaultSort(): string
    {
        return '-incident_date';
    }

    public function columns(): array
    {
        return [
            self::text('number'),
            self::employee(),
            ListColumn::make('category', 'hr.columns.category', ['category'], fn (array $row) => __('hr.disciplinary_categories.'.$row['category'])),
            self::date('incident_date'),
            self::status(),
        ];
    }

    public function exportRelations(): array
    {
        return ['employee'];
    }
}
