<?php

namespace Modules\CRM\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Support\CrmAccess;

/**
 * CRM's other reads, by route:
 *
 * - GET crm/customers/{party}/overview (CRM-25): any CRM view permission,
 *   and a party the user reaches (MD-01; 404 otherwise);
 * - GET crm/assignees?branch= (CRM-10): `crm.lead.assign`, `crm.scope.team`
 *   or `crm.scope.branch` anywhere; the users who may own records there;
 * - GET crm/pipeline-summary?pipeline= (CRM-16): `crm.opportunity.view`;
 * - GET crm/targets?company=&status= (CRM-30): `crm.target.view` or
 *   `crm.target.manage`.
 */
class ReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(CrmAccess::class);
        $user = $this->user();

        return match ((string) $this->route()?->getActionMethod()) {
            'overview' => $this->reachesParty() && $access->anywhere($user, 'crm.lead.view', 'crm.opportunity.view', 'crm.quote.view', 'crm.activity.view'),
            'assignees' => $access->anywhere($user, 'crm.lead.assign', CrmAccess::SCOPE_TEAM, CrmAccess::SCOPE_BRANCH, 'crm.target.manage'),
            'summary' => $access->anywhere($user, 'crm.opportunity.view'),
            default => $access->anywhere($user, 'crm.target.view', 'crm.target.manage'),
        };
    }

    public function rules(): array
    {
        return match ((string) $this->route()?->getActionMethod()) {
            'assignees' => ['branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')]],
            'summary' => ['pipeline' => ['required', 'uuid', Rule::exists('crm_pipelines', 'id')]],
            'overview' => [],
            default => [
                'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
                'status' => ['sometimes', 'string', Rule::in(['active', 'all'])],
            ],
        };
    }

    /** MD-01: the party is one the user reaches (404 otherwise). */
    private function reachesParty(): bool
    {
        $party = $this->route('party');
        abort_unless($party instanceof Party && app(CompanyReach::class)->reachesRecord($this->user(), $party->company_id, [...PartyPolicy::PERMISSIONS, 'crm.lead.view', 'crm.opportunity.view', 'crm.quote.view', 'crm.activity.view']), 404);

        return true;
    }
}
