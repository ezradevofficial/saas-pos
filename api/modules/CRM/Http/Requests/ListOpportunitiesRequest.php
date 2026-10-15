<?php

namespace Modules\CRM\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Http\Lists\OpportunityList;
use Modules\CRM\Models\Opportunity;

/** GET crm/opportunities (CRM-16): `crm.opportunity.view`; the list contract of ListsCrm and `?pipeline=` (the kanban); `?search=` on the name. */
class ListOpportunitiesRequest extends FormRequest
{
    use ListsCrm;

    public function list(): ListDefinition
    {
        return new OpportunityList;
    }

    protected function resource(): string
    {
        return 'opportunity';
    }

    protected function statuses(): array
    {
        return Opportunity::STATUSES;
    }

    protected function extraRules(): array
    {
        return ['pipeline' => ['sometimes', 'uuid', Rule::exists('crm_pipelines', 'id')]];
    }

    protected function applyExtraFilters(Builder $query): void
    {
        if ($this->filled('pipeline')) {
            $query->where($query->qualifyColumn('pipeline_id'), $this->validated('pipeline'));
        }
    }

    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('name'), 'ilike', $like);
    }
}
