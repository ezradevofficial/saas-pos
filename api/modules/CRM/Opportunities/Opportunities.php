<?php

namespace Modules\CRM\Opportunities;

use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\CRM\CustomFields\OpportunityEntity;
use Modules\CRM\Events\OpportunityLost;
use Modules\CRM\Events\OpportunityWon;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Option;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Settings;
use Modules\CRM\Models\Stage;
use Modules\CRM\Setup\CompanySetup;
use Modules\CRM\Setup\Configuration;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Records;
use Modules\CRM\Support\Tx;

/**
 * CRM-14 to CRM-16: opportunities.
 *
 * - create() / update(): for a customer (MD-01) at a branch, in an open
 *   stage of one of the company's pipelines (the default pipeline's first
 *   stage when none is named); the probability follows the stage unless
 *   one is given; value in minor units of an active currency.
 * - win() / lose() / reopen(): the outcome, its reason, the close date,
 *   and for a win the rate and base value at that date (CUR-04).
 */
class Opportunities
{
    public function __construct(
        private readonly Records $records,
        private readonly Configuration $configuration,
        private readonly CompanySetup $setup,
        private readonly CustomFieldWriter $customFields,
        private readonly Converter $converter,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by, ?string $leadId = null): Opportunity
    {
        return Tx::run(function () use ($data, $by, $leadId) {
            $branch = $this->records->branch((string) $data['branch_id']);
            $company = $branch->company;
            $this->setup->ensure($company);
            $party = $this->records->customer($company->id, (string) $data['party_id']);
            [$pipeline, $stage] = $this->place($company, $data['pipeline_id'] ?? null, $data['stage_id'] ?? null);

            $opportunity = new Opportunity([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'party_id' => $party->id,
                'lead_id' => $leadId,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'name' => $data['name'],
                'value_minor' => (int) ($data['value_minor'] ?? 0),
                'currency' => $this->records->currency((string) ($data['currency'] ?? $party->currency ?? $company->base_currency)),
                'expected_close_on' => $data['expected_close_on'] ?? null,
                'probability' => (int) ($data['probability'] ?? $stage->probability),
                'status' => Opportunity::OPEN,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ]);
            $ownerId = array_key_exists('owner_id', $data) ? $data['owner_id'] : ($this->records->eligible($by, $branch->id, 'opportunity') ? $by->id : null);
            $opportunity->owner_id = $ownerId === null ? null : $this->records->owner($ownerId, $branch->id, 'opportunity')->id;
            if (array_key_exists('team_id', $data)) {
                $opportunity->team_id = $data['team_id'] === null ? null : $this->records->team($company->id, $branch->id, $data['team_id'])->id;
            } else {
                $opportunity->team_id = $opportunity->owner_id === null ? null : $this->records->soleTeam($company->id, $branch->id, $opportunity->owner_id);
            }
            $this->customFields->fill(OpportunityEntity::KEY, $opportunity, $data['custom'] ?? null, creating: true);
            $opportunity->save();
            $this->customFields->saved(OpportunityEntity::KEY, $opportunity);

            return $opportunity->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Opportunity $opportunity, array $data): Opportunity
    {
        return Tx::run(function () use ($opportunity, $data) {
            $opportunity = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            $closedFields = array_diff(array_keys($data), ['notes', 'custom']);

            if ($opportunity->status !== Opportunity::OPEN && $closedFields !== []) {
                throw CrmError::make('opportunity_closed', status: 409);
            }

            $company = Company::query()->findOrFail($opportunity->company_id);

            if (array_key_exists('party_id', $data)) {
                $opportunity->party_id = $this->records->customer($company->id, (string) $data['party_id'])->id;
            }

            if (array_key_exists('pipeline_id', $data) || array_key_exists('stage_id', $data)) {
                $pipelineId = $data['pipeline_id'] ?? $opportunity->pipeline_id;
                $stageId = $data['stage_id'] ?? ($pipelineId === $opportunity->pipeline_id ? $opportunity->stage_id : null);
                [$pipeline, $stage] = $this->place($company, $pipelineId, $stageId);

                if ($stage->id !== $opportunity->stage_id) {
                    $opportunity->probability = (int) ($data['probability'] ?? $stage->probability);
                }

                $opportunity->pipeline_id = $pipeline->id;
                $opportunity->stage_id = $stage->id;
            }

            foreach (['name', 'expected_close_on', 'notes', 'probability'] as $key) {
                if (array_key_exists($key, $data)) {
                    $opportunity->{$key} = $data[$key];
                }
            }

            if (array_key_exists('value_minor', $data)) {
                $opportunity->value_minor = (int) $data['value_minor'];
            }

            if (array_key_exists('currency', $data)) {
                $opportunity->currency = $this->records->currency((string) $data['currency']);
            }

            if (array_key_exists('owner_id', $data)) {
                $opportunity->owner_id = $data['owner_id'] === null ? null : $this->records->owner($data['owner_id'], $opportunity->branch_id, 'opportunity')->id;
            }

            if (array_key_exists('team_id', $data)) {
                $opportunity->team_id = $data['team_id'] === null ? null : $this->records->team($company->id, $opportunity->branch_id, $data['team_id'])->id;
            }

            $this->customFields->fill(OpportunityEntity::KEY, $opportunity, $data['custom'] ?? null, creating: false);
            $opportunity->save();
            $this->customFields->saved(OpportunityEntity::KEY, $opportunity);

            return $opportunity->refresh();
        });
    }

    /** CRM-15: won, with an optional won reason; the base value frozen at the close date's rate. @param array<string, mixed> $data */
    public function win(Opportunity $opportunity, array $data, User $by): Opportunity
    {
        return Tx::run(function () use ($opportunity, $data, $by) {
            $opportunity = $this->open($opportunity);
            $company = Company::query()->findOrFail($opportunity->company_id);
            $closedOn = CarbonImmutable::parse($data['closed_on'] ?? CarbonImmutable::now($company->timezone ?: 'UTC')->toDateString());
            $fx = $this->fx($company, $opportunity->currency, $closedOn);

            $opportunity->fill([
                'status' => Opportunity::WON,
                'reason_id' => ($data['reason_id'] ?? null) === null ? null : $this->configuration->option($company->id, Option::WON_REASON, $data['reason_id'], 'reason_id')->id,
                'close_note' => $data['note'] ?? null,
                'closed_on' => $closedOn->toDateString(),
                'closed_by' => $by->id,
                'probability' => 100,
                'fx' => $fx,
                'base_currency' => $company->base_currency,
                'base_value_minor' => $fx->isIdentity() ? $opportunity->value_minor : (int) $fx->convert(Money::ofMinor((string) $opportunity->value_minor, $opportunity->currency))->minor(),
            ])->save();

            OpportunityWon::dispatch(...$this->payload($opportunity));

            return $opportunity;
        });
    }

    /** CRM-15: lost, with a lost reason (required when the settings say so). @param array<string, mixed> $data */
    public function lose(Opportunity $opportunity, array $data, User $by): Opportunity
    {
        return Tx::run(function () use ($opportunity, $data, $by) {
            $opportunity = $this->open($opportunity);
            $company = Company::query()->findOrFail($opportunity->company_id);
            $required = Settings::query()->where('company_id', $company->id)->value('lost_reason_required') ?? true;

            if (($data['reason_id'] ?? null) === null && $required) {
                throw CrmError::make('reason_required', field: 'reason_id');
            }

            $opportunity->fill([
                'status' => Opportunity::LOST,
                'reason_id' => ($data['reason_id'] ?? null) === null ? null : $this->configuration->option($company->id, Option::LOST_REASON, $data['reason_id'], 'reason_id')->id,
                'close_note' => $data['note'] ?? null,
                'closed_on' => CarbonImmutable::now($company->timezone ?: 'UTC')->toDateString(),
                'closed_by' => $by->id,
                'probability' => 0,
            ])->save();

            OpportunityLost::dispatch(...$this->payload($opportunity));

            return $opportunity;
        });
    }

    public function reopen(Opportunity $opportunity): Opportunity
    {
        return Tx::run(function () use ($opportunity) {
            $opportunity = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();

            if ($opportunity->status === Opportunity::OPEN) {
                throw CrmError::make('opportunity_open', status: 409);
            }

            $stage = Stage::query()->find($opportunity->stage_id);
            $opportunity->fill([
                'status' => Opportunity::OPEN, 'reason_id' => null, 'close_note' => null, 'closed_on' => null, 'closed_by' => null,
                'fx' => null, 'base_currency' => null, 'base_value_minor' => null, 'probability' => $stage?->probability ?? 0,
            ])->save();

            return $opportunity;
        });
    }

    public function archive(Opportunity $opportunity, bool $archive): Opportunity
    {
        return Tx::run(function () use ($opportunity, $archive) {
            $opportunity = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();

            if ($archive !== $opportunity->isArchived()) {
                $archive ? $opportunity->archive() : $opportunity->restore();
            }

            return $opportunity;
        });
    }

    /**
     * CRM-02: the pipeline (the company's default when none) and an open
     * stage of it (its first when none).
     *
     * @return array{0: Pipeline, 1: Stage}
     */
    public function place(Company $company, ?string $pipelineId, ?string $stageId): array
    {
        $pipeline = $pipelineId === null
            ? Pipeline::query()->where('company_id', $company->id)->where('is_default', true)->whereNull('archived_at')->first()
                ?? Pipeline::query()->where('company_id', $company->id)->whereNull('archived_at')->orderBy('created_at')->first()
            : Pipeline::query()->where('company_id', $company->id)->whereKey($pipelineId)->whereNull('archived_at')->first();

        if ($pipeline === null) {
            throw CrmError::make('pipeline_unknown', field: 'pipeline_id');
        }

        $stages = Stage::query()->where('pipeline_id', $pipeline->id)->whereNull('archived_at')->orderBy('position')->orderBy('created_at');
        $stage = $stageId === null ? $stages->first() : $stages->whereKey($stageId)->first();

        if ($stage === null) {
            throw CrmError::make('stage_unknown', field: 'stage_id');
        }

        return [$pipeline, $stage];
    }

    private function open(Opportunity $opportunity): Opportunity
    {
        $opportunity = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();

        if ($opportunity->status !== Opportunity::OPEN) {
            throw CrmError::make('opportunity_closed', status: 409);
        }

        return $opportunity;
    }

    /** CUR-04: the company's rate for $currency → base at the end of $date (identity in the base currency). */
    private function fx(Company $company, string $currency, CarbonImmutable $date): FxSnapshot
    {
        if ($currency === $company->base_currency) {
            return FxSnapshot::identity($currency);
        }

        $at = CarbonImmutable::parse($date->toDateString(), $company->timezone ?: 'UTC')->endOfDay();

        try {
            return $this->converter->toBase(Money::ofMinor(0, $currency), $company, $at)['snapshot'];
        } catch (RateUnavailable) {
            throw CrmError::make('rate_unavailable', ['pair' => "{$currency}/{$company->base_currency}", 'date' => $date->toDateString()], 'currency');
        }
    }

    /** @return list<mixed> */
    private function payload(Opportunity $opportunity): array
    {
        return [
            (string) $opportunity->tenant_id, $opportunity->id, $opportunity->company_id, $opportunity->branch_id, $opportunity->party_id, $opportunity->owner_id,
            $opportunity->currency, (string) $opportunity->value_minor, $opportunity->base_currency,
            $opportunity->base_value_minor === null ? null : (string) $opportunity->base_value_minor, $opportunity->closed_on->toDateString(),
        ];
    }
}
