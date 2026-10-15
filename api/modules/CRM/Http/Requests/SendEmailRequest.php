<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\CRM\Support\CrmAccess;

/**
 * POST crm/emails (CRM-29): `crm.activity.manage` (at the branch, checked
 * by Activities); `{to, subject, body, branch_id?, lead_id?,
 * opportunity_id?, quote_id?, party_id?}`: a branch or a linked record.
 */
class SendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CrmAccess::class)->anywhere($this->user(), 'crm.activity.manage');
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'branch_id' => CrmRules::id('branches'),
            'lead_id' => CrmRules::id('crm_leads'),
            'opportunity_id' => CrmRules::id('crm_opportunities'),
            'quote_id' => CrmRules::id('crm_quotes'),
            'party_id' => CrmRules::id('parties'),
        ];
    }
}
