<?php

namespace Modules\CRM\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Http\Lists\ActivityList;
use Modules\CRM\Models\Activity;

/**
 * GET crm/activities (CRM-26, CRM-28): `crm.activity.view`; the list
 * contract of ListsCrm with `?from=` and `?to=` on the start (the
 * calendar's range) and `?kind=`; `?search=` on the subject.
 */
class ListActivitiesRequest extends FormRequest
{
    use ListsCrm;

    public function list(): ListDefinition
    {
        return new ActivityList;
    }

    protected function resource(): string
    {
        return 'activity';
    }

    protected function statuses(): array
    {
        return Activity::STATUSES;
    }

    protected function archivable(): bool
    {
        return false;
    }

    protected function dateColumn(): string
    {
        return 'starts_at';
    }

    protected function extraRules(): array
    {
        return ['kind' => ['sometimes', 'string', Rule::in(Activity::KINDS)]];
    }

    protected function applyExtraFilters(Builder $query): void
    {
        if ($this->filled('kind')) {
            $query->where($query->qualifyColumn('kind'), $this->validated('kind'));
        }
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('subject'), 'ilike', $like);
    }
}
