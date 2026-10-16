<?php

namespace Modules\CRM\Http\Requests\Setup;

use Illuminate\Validation\Rule;
use Modules\CRM\Http\Requests\CrmRules;
use Modules\CRM\Http\Requests\SetupRequest;
use Modules\CRM\Models\Lead;

/**
 * CRM-11: POST crm/assignment-rules `{company_id, name, position?,
 * branch_id?, source_ids?, channels?, assignee_type: user|team, user_id?,
 * team_id?, active?}`; PATCH crm/assignment-rules/{id} the same but the
 * company; archive.
 */
class AssignmentRuleRequest extends SetupRequest
{
    protected function parameter(): ?string
    {
        return 'crm_assignment_rule';
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
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'branch_id' => CrmRules::id('branches'),
            'source_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'source_ids.*' => ['uuid', 'distinct', Rule::exists('crm_options', 'id')],
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['string', 'distinct', Rule::in(Lead::CHANNELS)],
            'assignee_type' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['user', 'team'])],
            'user_id' => ['required_if:assignee_type,user', 'nullable', 'uuid', Rule::exists('users', 'id')],
            'team_id' => ['required_if:assignee_type,team', 'nullable', 'uuid', Rule::exists('crm_teams', 'id')],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
