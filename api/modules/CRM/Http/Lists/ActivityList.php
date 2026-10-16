<?php

namespace Modules\CRM\Http\Lists;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListSort;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Http\Resources\ActivityResource;

/** CRM-26, CRM-28, CRM-32: the activities list (the calendar's source) and its export, by start time. */
class ActivityList extends CrmList
{
    public function name(): string
    {
        return 'crm-activities';
    }

    public function auditAction(): string
    {
        return 'crm.activity.export';
    }

    public function title(array $filters): string
    {
        return __('crm.lists.activities');
    }

    public function resource(Model $model): JsonResource
    {
        return ActivityResource::make($model);
    }

    public function sorts(): array
    {
        return [...parent::sorts(), 'starts_at' => ListSort::column('starts_at')];
    }

    public function defaultSort(): string
    {
        return 'starts_at';
    }

    public function columns(): array
    {
        return [
            self::at('starts_at'),
            ListColumn::make('kind', 'crm.columns.kind', ['kind'], fn (array $row) => __('crm.activity_kinds.'.$row['kind'])),
            self::text('subject'),
            self::status(),
            self::named('owner'),
            self::named('party'),
            self::named('opportunity'),
            self::named('branch'),
            self::at('done_at'),
        ];
    }

    public function exportRelations(): array
    {
        return ActivityResource::RELATIONS;
    }
}
