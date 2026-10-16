<?php

namespace Modules\CRM\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\CRM\CustomFields\OpportunityEntity;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Support\CrmAccess;

/**
 * POST crm/opportunities (CRM-14): `crm.opportunity.create` at the branch;
 * `{branch_id, party_id, name, pipeline_id?, stage_id?, value_minor?,
 * currency?, expected_close_on?, probability?, owner_id?, team_id?,
 * notes?, custom?}`. PATCH crm/opportunities/{id}: the same fields but the
 * branch, each optional, `crm.opportunity.edit` on one the user sees (404
 * otherwise); moving a stage is a PATCH of `stage_id`. Field rules
 * (RBAC-05, `crm_opportunity`) refuse hidden or read-only fields.
 */
class OpportunityRequest extends FormRequest
{
    use GuardsFieldRules;

    protected string $fieldRulesResource = OpportunityEntity::KEY;

    /** @var array<string, list<string>> */
    protected array $fieldRulesInputs = ['value_minor' => ['value']];

    public function authorize(): bool
    {
        $access = app(CrmAccess::class);
        $opportunity = $this->opportunity();

        if ($opportunity !== null) {
            abort_unless($access->sees($this->user(), $opportunity, 'opportunity'), 404);

            return $access->canAt($this->user(), 'crm.opportunity.edit', $opportunity->branch_id);
        }

        return $access->anywhere($this->user(), 'crm.opportunity.create');
    }

    public function rules(): array
    {
        $creating = $this->opportunity() === null;

        return [
            ...($creating ? ['branch_id' => CrmRules::id('branches', required: true)] : []),
            'party_id' => CrmRules::id('parties', required: $creating),
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'pipeline_id' => CrmRules::id('crm_pipelines'),
            'stage_id' => CrmRules::id('crm_stages'),
            'value_minor' => ['sometimes', 'string', CrmRules::MINOR],
            'currency' => ['sometimes', 'string', 'size:3'],
            'expected_close_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'probability' => ['sometimes', 'integer', 'between:0,100'],
            'owner_id' => CrmRules::id('users'),
            'team_id' => CrmRules::id('crm_teams'),
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ...CustomFieldValidator::rules(),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && $this->opportunity() === null) {
                    app(CrmAccess::class)->assertAt($this->user(), 'crm.opportunity.create', (string) $this->input('branch_id'));
                }
            },
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, OpportunityEntity::KEY, $this->input('custom'), $this->opportunity(), $this->user()),
        ];
    }

    public function opportunity(): ?Opportunity
    {
        $opportunity = $this->route('crm_opportunity');

        return $opportunity instanceof Opportunity ? $opportunity : null;
    }
}
