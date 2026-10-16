<?php

namespace Modules\CRM\Http\Requests\Setup;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Http\Requests\SetupRequest;
use Modules\CRM\Models\Option;
use Modules\CRM\Support\CrmAccess;

/**
 * GET crm/pipelines, crm/options, crm/teams (any CRM permission: forms
 * and pickers read them), crm/assignment-rules and crm/lead-forms
 * (`crm.settings.manage`): the companies the user reaches, `?company=`,
 * `?kind=` (options), `?status=` (`active` by default, `archived`, `all`).
 */
class SetupListRequest extends FormRequest
{
    private const MANAGED = ['rules', 'forms'];

    public function authorize(): bool
    {
        $access = app(CrmAccess::class);

        return in_array($this->route()?->getActionMethod(), self::MANAGED, true)
            ? $access->anywhere($this->user(), 'crm.settings.manage')
            : $access->anywhere($this->user(), ...SetupRequest::REACH);
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'kind' => ['sometimes', 'string', Rule::in(Option::KINDS)],
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])],
        ];
    }

    public function applyFilters(Builder $query, string $companyColumn = 'company_id'): Builder
    {
        $access = app(CrmAccess::class);
        $companies = in_array($this->route()?->getActionMethod(), self::MANAGED, true)
            ? $access->companies($this->user(), 'crm.settings.manage')
            : $access->companies($this->user(), ...SetupRequest::REACH);

        if ($companies !== null) {
            $query->whereIn($query->qualifyColumn($companyColumn), $companies);
        }

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn($companyColumn), $this->validated('company'));
        }

        match ($this->validated('status', 'active')) {
            'archived' => $query->whereNotNull($query->qualifyColumn('archived_at')),
            'all' => null,
            default => $query->whereNull($query->qualifyColumn('archived_at')),
        };

        return $query;
    }
}
