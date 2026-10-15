<?php

namespace Modules\CRM\Http\Requests\Setup;

use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Modules\CRM\Http\Requests\CrmRules;
use Modules\CRM\Http\Requests\SetupRequest;
use Modules\CRM\Support\CrmAccess;

/**
 * CRM-01: GET crm/companies/{company}/settings (any CRM permission at the
 * company, 404 otherwise) and PUT `{quote_approval_minor?,
 * quote_validity_days?, default_team_id?, lost_reason_required?}`
 * (`crm.settings.manage` at the company, 403). The company is the route's.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->company();
        $access = app(CrmAccess::class);
        $reached = $access->companies($this->user(), ...SetupRequest::REACH);
        abort_unless($company !== null && ($reached === null || in_array($company->id, $reached, true)), 404);

        return $this->isMethod('GET') || $access->canAtCompany($this->user(), 'crm.settings.manage', $company->id);
    }

    public function company(): ?Company
    {
        $company = $this->route('company');

        return $company instanceof Company ? $company : null;
    }

    public function rules(): array
    {
        return $this->isMethod('GET') ? [] : [
            'quote_approval_minor' => ['sometimes', 'nullable', 'string', CrmRules::MINOR],
            'quote_validity_days' => ['sometimes', 'integer', 'between:1,365'],
            'default_team_id' => CrmRules::id('crm_teams'),
            'lost_reason_required' => ['sometimes', 'boolean'],
        ];
    }
}
