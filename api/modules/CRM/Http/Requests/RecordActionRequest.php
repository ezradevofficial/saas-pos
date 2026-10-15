<?php

namespace Modules\CRM\Http\Requests;

use App\Core\MasterData\Parties\Party;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\Quote;

/**
 * The actions on one CRM record (POST crm/leads/{id}/assign|convert|
 * disqualify|archive|restore, crm/opportunities/{id}/win|lose|reopen|
 * archive|restore, crm/quotes/{id}/submit|accept|decline|convert|cancel|
 * send|share, its shares and PDF, crm/activities/{id}/complete|cancel|
 * reopen): not found unless the user sees the record (ShowRecordRequest);
 * then the action's permission at its branch (403):
 *
 * - leads: assign → `assign`, convert → `convert`, the rest → `archive`;
 * - opportunities: win, lose, reopen → `close`; archive, restore → `archive`;
 * - quotes: submit, accept, decline → `create`; cancel → `cancel`;
 *   convert → `convert`; send, share, shares, revoke and pdf → `send`;
 * - activities: `manage`.
 */
class RecordActionRequest extends ShowRecordRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return $this->mayAt($this->permissionAction());
    }

    public function action(): string
    {
        return (string) $this->route()->getActionMethod();
    }

    private function permissionAction(): string
    {
        return match ($this->resource()) {
            'lead' => match ($this->action()) {
                'assign' => 'assign',
                'convert' => 'convert',
                default => 'archive',
            },
            'opportunity' => in_array($this->action(), ['win', 'lose', 'reopen'], true) ? 'close' : 'archive',
            'quote' => match ($this->action()) {
                'cancel' => 'cancel',
                'convert' => 'convert',
                'send', 'share', 'shares', 'revokeShare', 'pdf' => 'send',
                default => 'create',
            },
            default => 'manage',
        };
    }

    public function rules(): array
    {
        return match ($this->resource().'.'.$this->action()) {
            'lead.assign' => [
                'owner_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
                'team_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('crm_teams', 'id')],
            ],
            'lead.convert' => [
                'party_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
                'party' => ['sometimes', 'array:kind,name,company_id,tax_id,phones,emails'],
                'party.kind' => ['sometimes', 'string', Rule::in(Party::KINDS)],
                'party.name' => ['sometimes', 'string', 'max:255'],
                'party.company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')],
                'party.tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
                'party.phones' => ['sometimes', 'array', 'max:10'],
                'party.phones.*.number' => ['required', 'string', 'max:30'],
                'party.emails' => ['sometimes', 'array', 'max:10'],
                'party.emails.*.address' => ['required', 'string', 'email', 'max:254'],
                'opportunity' => ['sometimes', 'nullable', 'array:name,pipeline_id,stage_id,value_minor,currency,expected_close_on'],
                'opportunity.name' => ['sometimes', 'string', 'max:200'],
                'opportunity.pipeline_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('crm_pipelines', 'id')],
                'opportunity.stage_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('crm_stages', 'id')],
                'opportunity.value_minor' => ['sometimes', 'string', CrmRules::MINOR],
                'opportunity.currency' => ['sometimes', 'string', 'size:3'],
                'opportunity.expected_close_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            ],
            'lead.disqualify' => ['reason' => ['required', 'string', 'max:1000']],
            'opportunity.win' => [
                'reason_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('crm_options', 'id')],
                'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'closed_on' => ['sometimes', 'date_format:Y-m-d'],
            ],
            'opportunity.lose' => [
                'reason_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('crm_options', 'id')],
                'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'quote.accept' => ['win_opportunity' => ['sometimes', 'boolean']],
            'quote.decline', 'quote.cancel' => ['reason' => ['required', 'string', 'max:1000']],
            'quote.convert' => ['target' => ['required', 'string', Rule::in(Quote::TARGETS)]],
            'quote.send' => ['email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'], 'locale' => ['sometimes', 'string', 'in:en,fr']],
            'activity.complete' => ['outcome' => ['sometimes', 'nullable', 'string', 'max:2000']],
            default => [],
        };
    }
}
