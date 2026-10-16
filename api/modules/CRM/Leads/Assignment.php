<?php

namespace Modules\CRM\Leads;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use Modules\CRM\Models\AssignmentRule;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadForm;
use Modules\CRM\Models\Settings;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;
use Modules\CRM\Support\Records;

/**
 * CRM-11: who gets a lead that arrived without an owner (form, import,
 * API). In order:
 *
 * 1. the lead form's team, when the form names one;
 * 2. the company's active rules by position: a rule matches when its
 *    branch (if any) is the lead's, the lead's source is among its
 *    sources (if any) and its channel among its channels (if any); it
 *    gives the lead to its user (when eligible) or deals it in its team;
 * 3. the settings' default team.
 *
 * A team deals round-robin among its eligible members (active, may view
 * leads at the lead's branch), ordered by user id, from its `turn`, taken
 * under the team's row lock (two leads at once never get the same turn).
 * Nobody eligible: the lead stays unassigned (branch managers see it).
 * The outcome is audited on the lead (`crm.lead.assign`).
 */
class Assignment
{
    public function __construct(
        private readonly Records $records,
        private readonly Auditor $auditor,
    ) {}

    /** Give $lead an owner and team by the rules (inside the caller's transaction). */
    public function deal(Lead $lead): void
    {
        if ($lead->owner_id !== null) {
            return;
        }

        $by = null;
        $form = $lead->form_id === null ? null : LeadForm::query()->find($lead->form_id);

        if ($form?->team_id !== null && ($owner = $this->roundRobin($form->team_id, $lead)) !== null) {
            $by = ['form' => $form->id];
            $this->give($lead, $owner, $form->team_id, $by);

            return;
        }

        $rules = AssignmentRule::query()->where('company_id', $lead->company_id)->where('active', true)->whereNull('archived_at')
            ->orderBy('position')->orderBy('created_at')->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $lead)) {
                continue;
            }

            if ($rule->assignee_type === 'user') {
                $user = User::query()->find($rule->user_id);

                if ($user !== null && $this->records->eligible($user, $lead->branch_id, 'lead')) {
                    $this->give($lead, $user->id, $this->records->soleTeam($lead->company_id, $lead->branch_id, $user->id), ['rule' => $rule->id]);

                    return;
                }

                continue;
            }

            if (($owner = $this->roundRobin($rule->team_id, $lead)) !== null) {
                $this->give($lead, $owner, $rule->team_id, ['rule' => $rule->id]);

                return;
            }
        }

        $default = Settings::query()->where('company_id', $lead->company_id)->value('default_team_id');

        if ($default !== null && ($owner = $this->roundRobin($default, $lead)) !== null) {
            $this->give($lead, $owner, $default, ['default_team' => $default]);
        }
    }

    private function matches(AssignmentRule $rule, Lead $lead): bool
    {
        return ($rule->branch_id === null || $rule->branch_id === $lead->branch_id)
            && ($rule->source_ids === [] || in_array($lead->source_id, $rule->source_ids, true))
            && ($rule->channels === [] || in_array($lead->channel, $rule->channels, true));
    }

    /** The next eligible member of the team for $lead, the team's turn moved past them; null when none. */
    public function roundRobin(string $teamId, Lead $lead): ?string
    {
        $team = Team::query()->whereKey($teamId)->whereNull('archived_at')->lockForUpdate()->first();

        if ($team === null || ($team->branch_id !== null && $team->branch_id !== $lead->branch_id)) {
            return null;
        }

        $members = TeamMember::query()->where('team_id', $team->id)->orderBy('user_id')->pluck('user_id')->all();
        $count = count($members);

        if ($count === 0) {
            return null;
        }

        $users = User::query()->whereKey($members)->get()->keyBy('id');

        for ($step = 0; $step < $count; $step++) {
            $index = ($team->turn + $step) % $count;
            $user = $users->get($members[$index]);

            if ($user !== null && $this->records->eligible($user, $lead->branch_id, 'lead')) {
                $team->fill(['turn' => ($index + 1) % $count])->save();

                return $user->id;
            }
        }

        return null;
    }

    /** @param array<string, string> $by */
    private function give(Lead $lead, string $ownerId, ?string $teamId, array $by): void
    {
        $lead->fill(['owner_id' => $ownerId, 'team_id' => $teamId])->save();
        $this->auditor->record('crm.lead.assign', $lead, null, ['owner_id' => $ownerId, 'team_id' => $teamId, 'by' => $by]);
    }
}
