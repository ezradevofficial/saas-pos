<?php

namespace Modules\CRM\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use Modules\CRM\Models\AssignmentRule;
use Modules\CRM\Models\LeadForm;
use Modules\CRM\Models\Option;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Settings;
use Modules\CRM\Models\Stage;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;

/** CRM-01 to CRM-06, CRM-11: how the module's configuration reads in the API. */
final class SetupResource
{
    /** @return array<string, mixed> */
    public static function settings(Settings $settings, Company $company, bool $manage): array
    {
        return [
            'company_id' => $company->id,
            'currency' => $company->base_currency,
            'quote_approval_minor' => $settings->quote_approval_minor === null ? null : (string) $settings->quote_approval_minor,
            'quote_validity_days' => $settings->quote_validity_days,
            'default_team' => Refs::named($settings->defaultTeam),
            'lost_reason_required' => (bool) $settings->lost_reason_required,
            'can' => ['manage' => $manage],
        ];
    }

    /** @return array<string, mixed> */
    public static function pipeline(Pipeline $pipeline): array
    {
        return [
            'id' => $pipeline->id,
            'company' => Refs::named($pipeline->company),
            'name' => $pipeline->name,
            'is_default' => (bool) $pipeline->is_default,
            'archived_at' => Refs::at($pipeline->archived_at),
            'stages' => $pipeline->stages->map(fn (Stage $stage) => self::stage($stage))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function stage(Stage $stage): array
    {
        return [
            'id' => $stage->id,
            'pipeline_id' => $stage->pipeline_id,
            'name' => $stage->name,
            'position' => $stage->position,
            'probability' => $stage->probability,
            'archived_at' => Refs::at($stage->archived_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function option(Option $option): array
    {
        return [
            'id' => $option->id,
            'company' => Refs::named($option->company),
            'kind' => $option->kind,
            'name' => $option->name,
            'position' => $option->position,
            'archived_at' => Refs::at($option->archived_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function team(Team $team): array
    {
        return [
            'id' => $team->id,
            'company' => Refs::named($team->company),
            'branch' => Refs::named($team->branch),
            'name' => $team->name,
            'leader' => Refs::user($team->leader),
            'members' => $team->members->map(fn (TeamMember $member) => Refs::user($member->user))->filter()->values()->all(),
            'archived_at' => Refs::at($team->archived_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function rule(AssignmentRule $rule): array
    {
        $sources = Option::query()->whereKey($rule->source_ids ?? [])->orderBy('position')->get();
        $assignee = $rule->assignee_type === 'user' ? $rule->user : $rule->team;

        return [
            'id' => $rule->id,
            'company' => Refs::named($rule->company),
            'name' => $rule->name,
            'position' => $rule->position,
            'branch' => Refs::named($rule->branch),
            'sources' => $sources->map(fn (Option $option) => Refs::named($option))->values()->all(),
            'channels' => array_values($rule->channels ?? []),
            'assignee' => $assignee === null ? null : [
                'type' => $rule->assignee_type, 'id' => (string) $assignee->getKey(), 'name' => (string) ($assignee instanceof User ? $assignee->name : $assignee->getAttribute('name')),
            ],
            'active' => (bool) $rule->active,
            'archived_at' => Refs::at($rule->archived_at),
        ];
    }

    /** @return array<string, mixed> the form; `url` only when a token was just made (shown once) */
    public static function form(LeadForm $form, ?string $token = null): array
    {
        return [
            'id' => $form->id,
            'company' => Refs::named($form->company),
            'branch' => Refs::named($form->branch),
            'name' => $form->name,
            'source' => Refs::named($form->source),
            'team' => Refs::named($form->team),
            'fields' => $form->fields,
            'submissions' => $form->submissions,
            'last_submitted_at' => Refs::at($form->last_submitted_at),
            'token_rotated_at' => Refs::at($form->token_rotated_at),
            'archived_at' => Refs::at($form->archived_at),
            ...($token === null ? [] : ['url' => rtrim((string) config('app.url'), '/').'/api/v1/crm/forms/'.$token]),
        ];
    }
}
