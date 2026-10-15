<?php

namespace Modules\HR\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Http\Resources\PositionResource;

/** HR-08, HR-35: positions. */
class PositionList extends HrList
{
    public function name(): string
    {
        return 'hr-positions';
    }

    public function auditAction(): string
    {
        return 'hr.position.export';
    }

    public function title(array $filters): string
    {
        return __('hr.lists.positions');
    }

    public function resource(Model $model): JsonResource
    {
        return PositionResource::make($model);
    }

    public function sorts(): array
    {
        return ['code' => ListSort::column('code'), 'title' => ListSort::column('title')];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function columns(): array
    {
        return [
            self::text('code'),
            self::text('title'),
            self::named('company', 'company'),
            ListColumn::make('department', 'hr.columns.department', ['department'], fn (array $row) => $row['department']['name'] ?? null),
            self::text('grade'),
        ];
    }

    public function exportRelations(): array
    {
        return ['company', 'department'];
    }
}
