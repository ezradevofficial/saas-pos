<?php

namespace Modules\CRM\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\CRM\CustomFields\LeadEntity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Support\CrmAccess;

/**
 * POST crm/leads (CRM-05, CRM-07): `crm.lead.create` at the branch;
 * `{branch_id, name, organisation?, title?, email?, phone?, source_id?,
 * estimated_value_minor?, currency?, notes?, owner_id?, team_id?, status?,
 * channel?: manual|api, custom?}`. PATCH crm/leads/{id}: the same fields
 * but the branch and channel, each optional, `crm.lead.edit` on a lead the
 * user sees (404 otherwise). Field rules (RBAC-05, `crm_lead`) refuse
 * hidden or read-only fields.
 */
class LeadRequest extends FormRequest
{
    use GuardsFieldRules;

    protected string $fieldRulesResource = LeadEntity::KEY;

    public function authorize(): bool
    {
        $access = app(CrmAccess::class);
        $lead = $this->lead();

        if ($lead !== null) {
            abort_unless($access->sees($this->user(), $lead, 'lead'), 404);

            return $access->canAt($this->user(), 'crm.lead.edit', $lead->branch_id);
        }

        return $access->anywhere($this->user(), 'crm.lead.create');
    }

    public function rules(): array
    {
        $creating = $this->lead() === null;

        return [
            ...($creating ? [
                'branch_id' => CrmRules::id('branches', required: true),
                'channel' => ['sometimes', 'string', Rule::in(['manual', 'api'])],
            ] : []),
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[+0-9 ()\-]{3,30}$/'],
            'source_id' => CrmRules::id('crm_options'),
            'estimated_value_minor' => ['sometimes', 'nullable', 'string', CrmRules::MINOR],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'owner_id' => CrmRules::id('users'),
            'team_id' => CrmRules::id('crm_teams'),
            'status' => ['sometimes', 'string', Rule::in(Lead::OPEN)],
            ...CustomFieldValidator::rules(),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && $this->lead() === null) {
                    app(CrmAccess::class)->assertAt($this->user(), 'crm.lead.create', (string) $this->input('branch_id'));
                }
            },
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, LeadEntity::KEY, $this->input('custom'), $this->lead(), $this->user()),
        ];
    }

    public function lead(): ?Lead
    {
        $lead = $this->route('crm_lead');

        return $lead instanceof Lead ? $lead : null;
    }
}
