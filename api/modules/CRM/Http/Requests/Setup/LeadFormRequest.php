<?php

namespace Modules\CRM\Http\Requests\Setup;

use Illuminate\Validation\Rule;
use Modules\CRM\Http\Requests\CrmRules;
use Modules\CRM\Http\Requests\SetupRequest;
use Modules\CRM\Models\LeadForm;

/**
 * CRM-06: POST crm/lead-forms `{company_id, branch_id, name, source_id?,
 * team_id?, fields?: {shown: [], required: []}}` (answers the link once);
 * PATCH crm/lead-forms/{id} the same but the company; POST …/rotate (a new
 * link, answered once) and …/archive.
 */
class LeadFormRequest extends SetupRequest
{
    protected function parameter(): ?string
    {
        return 'crm_lead_form';
    }

    public function rules(): array
    {
        $action = (string) $this->route()?->getActionMethod();

        if (! in_array($action, ['store', 'update'], true)) {
            return [];
        }

        $creating = $action === 'store';

        return [
            ...($creating ? ['company_id' => CrmRules::id('companies', required: true)] : []),
            'branch_id' => CrmRules::id('branches', required: $creating),
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'source_id' => CrmRules::id('crm_options'),
            'team_id' => CrmRules::id('crm_teams'),
            'fields' => ['sometimes', 'array:shown,required'],
            'fields.shown' => ['sometimes', 'array'],
            'fields.shown.*' => ['string', Rule::in(LeadForm::FIELDS)],
            'fields.required' => ['sometimes', 'array'],
            'fields.required.*' => ['string', Rule::in(LeadForm::FIELDS)],
        ];
    }
}
