<?php

namespace Modules\CRM\Leads;

use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\CRM\CustomFields\LeadEntity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadForm;
use Modules\CRM\Models\Option;
use Modules\CRM\Setup\CompanySetup;
use Modules\CRM\Setup\Configuration;
use Modules\CRM\Support\CrmAccess;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Records;
use Modules\CRM\Support\Tx;

/**
 * CRM-05, CRM-07, CRM-10, CRM-11, CRM-13: leads.
 *
 * - create(): at a branch (its company); the owner is the one given
 *   (`crm.lead.assign` when it is someone else, CRM-10), else the creator
 *   when they may view leads there (typed by a person), else the
 *   assignment rules (form, import, API without an owner, CRM-11).
 * - update(): details and status (`new`, `contacted`, `qualified`, the
 *   kanban moves); converted and disqualified leads are closed.
 * - assign(), disqualify(), archive() and restore().
 */
class Leads
{
    public function __construct(
        private readonly Records $records,
        private readonly Assignment $assignment,
        private readonly Configuration $configuration,
        private readonly CompanySetup $setup,
        private readonly CustomFieldWriter $customFields,
        private readonly CrmAccess $access,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     * @param  ?User  $by  the person (null: a public form)
     */
    public function create(array $data, ?User $by, string $channel, ?LeadForm $form = null): Lead
    {
        return Tx::run(function () use ($data, $by, $channel, $form) {
            $branch = $this->records->branch((string) $data['branch_id']);
            $company = $branch->company;
            $this->setup->ensure($company);

            $lead = new Lead([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'status' => $data['status'] ?? Lead::NEW,
                'channel' => $channel,
                'form_id' => $form?->id,
                'created_by' => $by?->id,
            ]);
            $this->fill($lead, $data);

            if (! array_key_exists('source_id', $data) && $form?->source_id !== null) {
                $lead->source_id = $form->source_id;
            }

            $this->owner($lead, $data, $by, creating: true);
            $this->customFields->fill(LeadEntity::KEY, $lead, $data['custom'] ?? null, creating: true);
            $lead->save();
            $this->customFields->saved(LeadEntity::KEY, $lead);

            if ($lead->owner_id === null) {
                $this->assignment->deal($lead);
            }

            return $lead->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Lead $lead, array $data, User $by): Lead
    {
        return Tx::run(function () use ($lead, $data, $by) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if (! $lead->isOpen()) {
                throw CrmError::make('lead_closed', status: 409);
            }

            if (array_key_exists('status', $data)) {
                $lead->status = $data['status'];
            }

            $this->fill($lead, $data);
            $this->owner($lead, $data, $by, creating: false);
            $this->customFields->fill(LeadEntity::KEY, $lead, $data['custom'] ?? null, creating: false);
            $lead->save();
            $this->customFields->saved(LeadEntity::KEY, $lead);

            return $lead->refresh();
        });
    }

    /** CRM-10: a new owner and/or team. @param array<string, mixed> $data */
    public function assign(Lead $lead, array $data, User $by): Lead
    {
        return Tx::run(function () use ($lead, $data, $by) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if (! $lead->isOpen()) {
                throw CrmError::make('lead_closed', status: 409);
            }

            $this->owner($lead, $data, $by, creating: false);
            $lead->save();

            return $lead->refresh();
        });
    }

    public function disqualify(Lead $lead, string $reason): Lead
    {
        return Tx::run(function () use ($lead, $reason) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if (! $lead->isOpen()) {
                throw CrmError::make('lead_closed', status: 409);
            }

            $lead->fill(['status' => Lead::DISQUALIFIED, 'disqualify_reason' => $reason])->save();

            return $lead;
        });
    }

    public function archive(Lead $lead, bool $archive): Lead
    {
        return Tx::run(function () use ($lead, $archive) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if ($archive !== $lead->isArchived()) {
                $archive ? $lead->archive() : $lead->restore();
            }

            return $lead;
        });
    }

    /** @param array<string, mixed> $data */
    private function fill(Lead $lead, array $data): void
    {
        foreach (['name', 'organisation', 'title', 'email', 'phone', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
                $lead->{$key} = $value === '' ? null : ($key === 'email' && $value !== null ? mb_strtolower($value) : $value);
            }
        }

        if (array_key_exists('source_id', $data)) {
            $lead->source_id = $data['source_id'] === null ? null : $this->configuration->option($lead->company_id, Option::SOURCE, $data['source_id'], 'source_id')->id;
        }

        if (array_key_exists('estimated_value_minor', $data)) {
            $value = $data['estimated_value_minor'];
            $lead->estimated_value_minor = $value === null ? null : (int) $value;
            $currency = $data['currency'] ?? $lead->currency ?? $lead->company()->value('base_currency');
            $lead->currency = $value === null ? null : $this->records->currency((string) $currency);
        } elseif (array_key_exists('currency', $data) && $lead->estimated_value_minor !== null) {
            $lead->currency = $this->records->currency((string) $data['currency']);
        }
    }

    /**
     * CRM-10: the owner and team from the input. Giving the lead to someone
     * else (or changing its team) needs `crm.lead.assign` at the branch; a
     * person creating a lead without an owner owns it when they may.
     *
     * @param  array<string, mixed>  $data
     */
    private function owner(Lead $lead, array $data, ?User $by, bool $creating): void
    {
        $assigns = array_key_exists('owner_id', $data) || array_key_exists('team_id', $data);

        if ($assigns && $by !== null) {
            $ownerChange = array_key_exists('owner_id', $data) && $data['owner_id'] !== $lead->owner_id && ! ($creating && $data['owner_id'] === $by->id);
            $changes = $ownerChange || (array_key_exists('team_id', $data) && $data['team_id'] !== $lead->team_id);

            if ($changes && ! $this->access->canAt($by, 'crm.lead.assign', $lead->branch_id)) {
                throw new AuthorizationException(__('crm.errors.assign_forbidden'));
            }
        }

        if (array_key_exists('owner_id', $data)) {
            $lead->owner_id = $data['owner_id'] === null ? null : $this->records->owner($data['owner_id'], $lead->branch_id, 'lead')->id;
        } elseif ($creating && $by !== null && $lead->channel === 'manual' && $this->records->eligible($by, $lead->branch_id, 'lead')) {
            $lead->owner_id = $by->id;
        }

        if (array_key_exists('team_id', $data)) {
            $lead->team_id = $data['team_id'] === null ? null : $this->records->team($lead->company_id, $lead->branch_id, $data['team_id'])->id;
        } elseif ($lead->owner_id !== null && ($creating || array_key_exists('owner_id', $data))) {
            $lead->team_id = $this->records->soleTeam($lead->company_id, $lead->branch_id, $lead->owner_id);
        }
    }
}
