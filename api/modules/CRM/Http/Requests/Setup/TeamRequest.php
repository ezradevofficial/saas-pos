<?php

namespace Modules\CRM\Http\Requests\Setup;

use Modules\CRM\Http\Requests\CrmRules;
use Modules\CRM\Http\Requests\SetupRequest;

/** CRM-04: POST crm/teams `{company_id, branch_id?, name, leader_id?, members?: [{user_id}]}`; PATCH crm/teams/{id} the same but the company; archive and restore. */
class TeamRequest extends SetupRequest
{
    protected function parameter(): ?string
    {
        return 'crm_team';
    }

    public function rules(): array
    {
        $action = (string) $this->route()?->getActionMethod();

        if (! in_array($action, ['store', 'update'], true)) {
            return [];
        }

        return [
            ...($action === 'store' ? ['company_id' => CrmRules::id('companies', required: true)] : []),
            'branch_id' => CrmRules::id('branches'),
            'name' => [$action === 'store' ? 'required' : 'sometimes', 'string', 'max:120'],
            'leader_id' => CrmRules::id('users'),
            'members' => ['sometimes', 'array', 'max:200'],
            'members.*.user_id' => ['required', 'uuid', 'distinct', 'exists:users,id'],
        ];
    }
}
