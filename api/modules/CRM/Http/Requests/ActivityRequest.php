<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\Activity;
use Modules\CRM\Support\CrmAccess;

/**
 * POST crm/activities (CRM-26): `crm.activity.manage` (at the activity's
 * branch, checked by Activities); `{branch_id?, kind, subject, notes?,
 * starts_at, ends_at?, remind_at?, owner_id?, lead_id?, opportunity_id?,
 * quote_id?, party_id?, email?: {to, subject}}` (times ISO 8601). PATCH
 * crm/activities/{id}: the same fields, each optional, on an activity the
 * user sees (404 otherwise).
 */
class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(CrmAccess::class);
        $activity = $this->activity();

        if ($activity !== null) {
            abort_unless($access->sees($this->user(), $activity, 'activity'), 404);

            return $access->canAt($this->user(), 'crm.activity.manage', $activity->branch_id);
        }

        return $access->anywhere($this->user(), 'crm.activity.manage');
    }

    public function rules(): array
    {
        $creating = $this->activity() === null;

        return [
            ...($creating ? ['branch_id' => CrmRules::id('branches')] : []),
            'kind' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(Activity::KINDS)],
            'subject' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'starts_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'remind_at' => ['sometimes', 'nullable', 'date'],
            'owner_id' => CrmRules::id('users'),
            'lead_id' => CrmRules::id('crm_leads'),
            'opportunity_id' => CrmRules::id('crm_opportunities'),
            'quote_id' => CrmRules::id('crm_quotes'),
            'party_id' => CrmRules::id('parties'),
            'email' => ['sometimes', 'nullable', 'array:to,subject'],
            'email.to' => ['required_with:email', 'email:rfc', 'max:254'],
            'email.subject' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function activity(): ?Activity
    {
        $activity = $this->route('crm_activity');

        return $activity instanceof Activity ? $activity : null;
    }
}
