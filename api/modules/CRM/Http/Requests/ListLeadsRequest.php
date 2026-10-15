<?php

namespace Modules\CRM\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Http\Lists\LeadList;
use Modules\CRM\Models\Lead;

/** GET crm/leads (CRM-13): `crm.lead.view`; the list contract of ListsCrm; `?source=`; `?search=` on name, organisation, email and phone. */
class ListLeadsRequest extends FormRequest
{
    use ListsCrm;

    public function list(): ListDefinition
    {
        return new LeadList;
    }

    protected function resource(): string
    {
        return 'lead';
    }

    protected function statuses(): array
    {
        return Lead::STATUSES;
    }

    protected function extraRules(): array
    {
        return ['source' => ['sometimes', 'uuid', Rule::exists('crm_options', 'id')]];
    }

    protected function applyExtraFilters(Builder $query): void
    {
        if ($this->filled('source')) {
            $query->where($query->qualifyColumn('source_id'), $this->validated('source'));
        }
    }

    protected function searchRows(Builder $query, string $like): void
    {
        foreach (['name', 'organisation', 'email', 'phone'] as $index => $column) {
            $index === 0 ? $query->where($query->qualifyColumn($column), 'ilike', $like) : $query->orWhere($query->qualifyColumn($column), 'ilike', $like);
        }
    }
}
