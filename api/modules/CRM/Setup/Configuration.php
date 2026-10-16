<?php

namespace Modules\CRM\Setup;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Support\Str;
use Modules\CRM\Models\AssignmentRule;
use Modules\CRM\Models\LeadForm;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Option;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Settings;
use Modules\CRM\Models\Stage;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Tx;

/**
 * CRM-01 to CRM-04, CRM-06, CRM-11: the module's configuration, written
 * from validated input. Every reference is checked against the record's
 * company (row-level security already hides other tenants'). Audited
 * through the models (AUD-01); archived, never deleted (TEN-06).
 */
class Configuration
{
    public function __construct(private readonly CompanySetup $setup) {}

    /** @param array<string, mixed> $data */
    public function updateSettings(Company $company, array $data): Settings
    {
        return Tx::run(function () use ($company, $data) {
            $settings = $this->setup->ensure($company);
            $settings = Settings::query()->whereKey($settings->id)->lockForUpdate()->firstOrFail();

            foreach (['quote_approval_minor', 'quote_validity_days', 'lost_reason_required'] as $key) {
                if (array_key_exists($key, $data)) {
                    $settings->{$key} = $data[$key];
                }
            }

            if (array_key_exists('default_team_id', $data)) {
                $settings->default_team_id = $data['default_team_id'] === null ? null : $this->team($company->id, $data['default_team_id'], 'default_team_id')->id;
            }

            $settings->save();

            return $settings;
        });
    }

    /** @param array<string, mixed> $data */
    public function createPipeline(Company $company, array $data): Pipeline
    {
        return Tx::run(function () use ($company, $data) {
            $this->setup->ensure($company);
            $pipeline = Pipeline::query()->create(['company_id' => $company->id, 'name' => $data['name'], 'is_default' => false]);

            foreach (array_values($data['stages'] ?? []) as $index => $stage) {
                Stage::query()->create(['pipeline_id' => $pipeline->id, 'name' => $stage['name'], 'probability' => (int) $stage['probability'], 'position' => $index + 1]);
            }

            if ((bool) ($data['is_default'] ?? false)) {
                $this->makeDefault($pipeline);
            }

            return $pipeline;
        });
    }

    /** @param array<string, mixed> $data */
    public function updatePipeline(Pipeline $pipeline, array $data): Pipeline
    {
        return Tx::run(function () use ($pipeline, $data) {
            if (array_key_exists('name', $data)) {
                $pipeline->fill(['name' => $data['name']])->save();
            }

            if ((bool) ($data['is_default'] ?? false) && ! $pipeline->is_default) {
                $this->makeDefault($pipeline);
            }

            return $pipeline->refresh();
        });
    }

    public function archivePipeline(Pipeline $pipeline, bool $archive): Pipeline
    {
        return Tx::run(function () use ($pipeline, $archive) {
            if ($archive && $pipeline->is_default) {
                throw CrmError::make('pipeline_default', status: 409);
            }

            if ($archive && Opportunity::query()->where('pipeline_id', $pipeline->id)->where('status', Opportunity::OPEN)->whereNull('archived_at')->exists()) {
                throw CrmError::make('pipeline_in_use', status: 409);
            }

            $archive ? $pipeline->archive() : $pipeline->restore();

            return $pipeline;
        });
    }

    /** @param array<string, mixed> $data */
    public function addStage(Pipeline $pipeline, array $data): Stage
    {
        return Tx::run(function () use ($pipeline, $data) {
            $stage = Stage::query()->create([
                'pipeline_id' => $pipeline->id,
                'name' => $data['name'],
                'probability' => (int) $data['probability'],
                'position' => (int) (Stage::query()->where('pipeline_id', $pipeline->id)->max('position') ?? 0) + 1,
            ]);

            if (isset($data['position'])) {
                $this->move($stage, (int) $data['position']);
            }

            return $stage->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateStage(Stage $stage, array $data): Stage
    {
        return Tx::run(function () use ($stage, $data) {
            $stage->fill(array_intersect_key($data, array_flip(['name', 'probability'])))->save();

            if (isset($data['position'])) {
                $this->move($stage, (int) $data['position']);
            }

            return $stage->refresh();
        });
    }

    public function archiveStage(Stage $stage): Stage
    {
        return Tx::run(function () use ($stage) {
            if (Opportunity::query()->where('stage_id', $stage->id)->where('status', Opportunity::OPEN)->whereNull('archived_at')->exists()) {
                throw CrmError::make('stage_in_use', status: 409);
            }

            if (Stage::query()->where('pipeline_id', $stage->pipeline_id)->whereNull('archived_at')->whereKeyNot($stage->id)->doesntExist()) {
                throw CrmError::make('stage_last', status: 409);
            }

            $stage->archive();

            return $stage;
        });
    }

    /** @param array<string, mixed> $data */
    public function createOption(Company $company, array $data): Option
    {
        return Tx::run(function () use ($company, $data) {
            $this->setup->ensure($company);

            return Option::query()->create([
                'company_id' => $company->id, 'kind' => $data['kind'], 'name' => $data['name'],
                'position' => (int) ($data['position'] ?? ((int) Option::query()->where('company_id', $company->id)->where('kind', $data['kind'])->max('position') + 1)),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateOption(Option $option, array $data): Option
    {
        $option->fill(array_intersect_key($data, array_flip(['name', 'position'])))->save();

        return $option;
    }

    /** @param array<string, mixed> $data */
    public function saveTeam(?Team $team, Company $company, array $data): Team
    {
        return Tx::run(function () use ($team, $company, $data) {
            $team ??= new Team(['company_id' => $company->id]);

            if (array_key_exists('name', $data)) {
                $team->name = $data['name'];
            }

            if (array_key_exists('branch_id', $data)) {
                $team->branch_id = $data['branch_id'] === null ? null : $this->branch($company->id, $data['branch_id'])->id;
            }

            if (array_key_exists('leader_id', $data)) {
                $team->leader_id = $data['leader_id'] === null ? null : $this->user($data['leader_id'], 'leader_id')->id;
            }

            $team->save();

            if (array_key_exists('members', $data)) {
                $wanted = array_values(array_unique(array_map(fn (array $member) => $this->user($member['user_id'], 'members')->id, $data['members'])));
                $current = TeamMember::query()->where('team_id', $team->id)->pluck('user_id')->all();

                // A member who leaves is removed from the team (not a business record; audited as a delete by its audit entry).
                foreach (array_diff($current, $wanted) as $userId) {
                    $member = TeamMember::query()->where('team_id', $team->id)->where('user_id', $userId)->first();
                    app(Auditor::class)->record('crm.team_member.remove', $team, ['user_id' => $userId], null);
                    $member?->delete();
                }

                foreach (array_diff($wanted, $current) as $userId) {
                    TeamMember::query()->create(['team_id' => $team->id, 'user_id' => $userId]);
                }
            }

            return $team->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function saveRule(?AssignmentRule $rule, Company $company, array $data): AssignmentRule
    {
        return Tx::run(function () use ($rule, $company, $data) {
            $rule ??= new AssignmentRule(['company_id' => $company->id, 'position' => (int) AssignmentRule::query()->where('company_id', $company->id)->max('position') + 1]);

            foreach (['name', 'position', 'active'] as $key) {
                if (array_key_exists($key, $data)) {
                    $rule->{$key} = $data[$key];
                }
            }

            if (array_key_exists('branch_id', $data)) {
                $rule->branch_id = $data['branch_id'] === null ? null : $this->branch($company->id, $data['branch_id'])->id;
            }

            if (array_key_exists('source_ids', $data)) {
                $rule->source_ids = array_values(array_unique(array_map(fn (string $id) => $this->option($company->id, Option::SOURCE, $id, 'source_ids')->id, $data['source_ids'] ?? [])));
            }

            if (array_key_exists('channels', $data)) {
                $rule->channels = array_values(array_unique($data['channels'] ?? []));
            }

            if (array_key_exists('assignee_type', $data)) {
                $rule->assignee_type = $data['assignee_type'];
                $rule->user_id = $data['assignee_type'] === 'user' ? $this->user((string) ($data['user_id'] ?? ''), 'user_id')->id : null;
                $rule->team_id = $data['assignee_type'] === 'team' ? $this->team($company->id, (string) ($data['team_id'] ?? ''), 'team_id')->id : null;
            }

            $rule->source_ids ??= [];
            $rule->channels ??= [];
            $rule->save();

            return $rule->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: LeadForm, 1: ?string} the form and, when made, its token (shown once)
     */
    public function saveForm(?LeadForm $form, Company $company, array $data): array
    {
        return Tx::run(function () use ($form, $company, $data) {
            $token = null;

            if ($form === null) {
                $token = Str::random(40);
                $form = new LeadForm(['company_id' => $company->id, 'token_hash' => hash('sha256', $token)]);
            }

            if (array_key_exists('name', $data)) {
                $form->name = $data['name'];
            }

            if (array_key_exists('branch_id', $data)) {
                $form->branch_id = $this->branch($company->id, $data['branch_id'])->id;
            }

            if (array_key_exists('source_id', $data)) {
                $form->source_id = $data['source_id'] === null ? null : $this->option($company->id, Option::SOURCE, $data['source_id'], 'source_id')->id;
            }

            if (array_key_exists('team_id', $data)) {
                $form->team_id = $data['team_id'] === null ? null : $this->team($company->id, $data['team_id'], 'team_id')->id;
            }

            if (array_key_exists('fields', $data) || ! $form->exists) {
                $form->fields = self::fields($data['fields'] ?? []);
            }

            $form->save();

            return [$form->refresh(), $token];
        });
    }

    /** CRM-06: a new token; the old link stops working. @return array{0: LeadForm, 1: string} */
    public function rotateForm(LeadForm $form): array
    {
        $token = Str::random(40);
        $form->forceFill(['token_hash' => hash('sha256', $token), 'token_rotated_at' => now()])->save();

        return [$form, $token];
    }

    /**
     * The shown and required fields of a form: `name` is always shown and
     * required; required fields are shown.
     *
     * @param  array{shown?: list<string>, required?: list<string>}  $fields
     * @return array{shown: list<string>, required: list<string>}
     */
    public static function fields(array $fields): array
    {
        $shown = array_values(array_intersect(LeadForm::FIELDS, ['name', ...($fields['shown'] ?? []), ...($fields['required'] ?? [])]));
        $required = array_values(array_intersect(LeadForm::FIELDS, ['name', ...($fields['required'] ?? [])]));

        return ['shown' => $shown, 'required' => $required];
    }

    private function makeDefault(Pipeline $pipeline): void
    {
        foreach (Pipeline::query()->where('company_id', $pipeline->company_id)->where('is_default', true)->whereKeyNot($pipeline->id)->get() as $other) {
            $other->fill(['is_default' => false])->save();
        }

        $pipeline->fill(['is_default' => true])->save();
    }

    /** Put $stage at $position (1-based) among its pipeline's stages and renumber them. */
    private function move(Stage $stage, int $position): void
    {
        $others = Stage::query()->where('pipeline_id', $stage->pipeline_id)->whereKeyNot($stage->id)->orderBy('position')->orderBy('created_at')->get()->all();
        $position = max(1, min($position, count($others) + 1));
        array_splice($others, $position - 1, 0, [$stage]);

        foreach (array_values($others) as $index => $row) {
            if ($row->position !== $index + 1) {
                $row->fill(['position' => $index + 1])->save();
            }
        }
    }

    public function branch(string $companyId, string $branchId, string $field = 'branch_id'): Branch
    {
        return Branch::query()->where('company_id', $companyId)->whereKey($branchId)->whereNull('archived_at')->first()
            ?? throw CrmError::make('branch_not_of_company', field: $field);
    }

    public function team(string $companyId, string $teamId, string $field = 'team_id'): Team
    {
        return Team::query()->where('company_id', $companyId)->whereKey($teamId)->whereNull('archived_at')->first()
            ?? throw CrmError::make('team_unknown', field: $field);
    }

    public function option(string $companyId, string $kind, string $optionId, string $field): Option
    {
        return Option::query()->where('company_id', $companyId)->where('kind', $kind)->whereKey($optionId)->whereNull('archived_at')->first()
            ?? throw CrmError::make('option_unknown', field: $field);
    }

    public function user(string $userId, string $field): User
    {
        $user = Str::isUuid($userId) ? User::query()->find($userId) : null;

        return $user !== null && $user->isActive() ? $user : throw CrmError::make('user_unknown', field: $field);
    }
}
