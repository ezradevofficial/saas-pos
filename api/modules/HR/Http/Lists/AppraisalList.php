<?php

namespace Modules\HR\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Http\Resources\RecordResource;

/** HR-30, HR-35: appraisals. */
class AppraisalList extends HrList
{
    public function name(): string
    {
        return 'hr-appraisals';
    }

    public function auditAction(): string
    {
        return 'hr.appraisal.export';
    }

    public function title(array $filters): string
    {
        return __('hr.lists.appraisals');
    }

    public function resource(Model $model): JsonResource
    {
        return RecordResource::make($model);
    }

    public function sorts(): array
    {
        return ['created_at' => ListSort::column('created_at'), 'status' => ListSort::column('status')];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function columns(): array
    {
        return [
            self::employee(),
            ListColumn::make('cycle', 'hr.columns.cycle', ['cycle'], fn (array $row) => $row['cycle']['name'] ?? null),
            self::status(),
            self::text('overall_rating'),
        ];
    }

    public function exportRelations(): array
    {
        return ['employee', 'cycle', 'reviewer'];
    }
}
