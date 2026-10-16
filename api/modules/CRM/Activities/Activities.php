<?php

namespace Modules\CRM\Activities;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Quote;
use Modules\CRM\Models\ScopedRecord;
use Modules\CRM\Support\CrmAccess;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Records;
use Modules\CRM\Support\Tx;

/**
 * CRM-26 to CRM-28: follow-ups.
 *
 * - An activity is at a branch (the one given, else its linked record's),
 *   owned by its creator unless given to someone else, which needs
 *   `crm.scope.team` or `crm.scope.branch` there (a lead or manager hands
 *   out work); the owner must be able to view activities there.
 * - Links (lead, opportunity, quote) must be records the user sees, of
 *   the activity's company; a customer (party) must be shared or of that
 *   company. The team follows the linked record, else the owner's only team.
 * - A new reminder time arms the reminder again (CRM-27).
 */
class Activities
{
    private const LINKS = ['lead_id' => [Lead::class, 'lead'], 'opportunity_id' => [Opportunity::class, 'opportunity'], 'quote_id' => [Quote::class, 'quote']];

    public function __construct(
        private readonly Records $records,
        private readonly CrmAccess $access,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $system  a record the platform writes for an action $by
     *                        was allowed to take (a quote's send email): no
     *                        `crm.activity.manage` check
     */
    public function create(array $data, User $by, bool $system = false): Activity
    {
        return Tx::run(function () use ($data, $by, $system) {
            $linked = $this->linked($data, $by);
            $branchId = $data['branch_id'] ?? collect($linked)->filter()->first()?->branch_id ?? throw CrmError::make('branch_required', field: 'branch_id');
            $branch = $this->records->branch((string) $branchId);

            if (! $system) {
                $this->access->assertAt($by, 'crm.activity.manage', $branch->id);
            }

            foreach ($linked as $record) {
                if ($record !== null && $record->company_id !== $branch->company_id) {
                    throw CrmError::make('link_other_company', status: 422);
                }
            }

            $activity = new Activity([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'status' => Activity::OPEN,
                'created_by' => $by->id,
            ]);
            $this->fill($activity, $data, $linked);
            $activity->owner_id = $this->owner($activity, $data['owner_id'] ?? $by->id, $by);
            $activity->team_id = collect($linked)->filter()->first()?->team_id
                ?? $this->records->soleTeam($activity->company_id, $activity->branch_id, $activity->owner_id);
            $activity->save();

            return $activity->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Activity $activity, array $data, User $by): Activity
    {
        return Tx::run(function () use ($activity, $data, $by) {
            $activity = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            $linked = $this->linked($data, $by);

            foreach ($linked as $record) {
                if ($record !== null && $record->company_id !== $activity->company_id) {
                    throw CrmError::make('link_other_company', status: 422);
                }
            }

            $this->fill($activity, $data, $linked);

            if (array_key_exists('owner_id', $data) && $data['owner_id'] !== $activity->owner_id) {
                $activity->owner_id = $this->owner($activity, (string) $data['owner_id'], $by);
                // The new owner's team: the linked record's, else their only one at the branch.
                $activity->team_id = collect(self::LINKS)->map(fn ($link, $key) => $activity->{$key} === null ? null : $link[0]::query()->whereKey($activity->{$key})->value('team_id'))->filter()->first()
                    ?? $this->records->soleTeam($activity->company_id, $activity->branch_id, $activity->owner_id);
            }

            $activity->save();

            return $activity->refresh();
        });
    }

    public function complete(Activity $activity, ?string $outcome): Activity
    {
        return $this->status($activity, Activity::DONE, ['outcome' => $outcome, 'done_at' => CarbonImmutable::now()]);
    }

    public function cancel(Activity $activity): Activity
    {
        return $this->status($activity, Activity::CANCELLED, []);
    }

    public function reopen(Activity $activity): Activity
    {
        return Tx::run(function () use ($activity) {
            $activity = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            $activity->fill(['status' => Activity::OPEN, 'done_at' => null, 'outcome' => null])->save();

            return $activity;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function status(Activity $activity, string $status, array $attributes): Activity
    {
        return Tx::run(function () use ($activity, $status, $attributes) {
            $activity = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();

            if ($activity->status !== Activity::OPEN) {
                throw CrmError::make('activity_closed', status: 409);
            }

            $activity->fill(['status' => $status, ...$attributes])->save();

            return $activity;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{0: ?ScopedRecord, 1: ?ScopedRecord, 2: ?ScopedRecord}  $linked
     */
    private function fill(Activity $activity, array $data, array $linked): void
    {
        foreach (['kind', 'subject', 'notes', 'outcome'] as $key) {
            if (array_key_exists($key, $data)) {
                $activity->{$key} = $data[$key];
            }
        }

        foreach (['starts_at', 'ends_at', 'remind_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $activity->{$key} = $data[$key] === null ? null : CarbonImmutable::parse((string) $data[$key])->utc();
            }
        }

        if ($activity->ends_at !== null && $activity->ends_at->lessThan($activity->starts_at)) {
            throw CrmError::make('ends_before_start', field: 'ends_at');
        }

        // CRM-27: a reminder moved is armed again.
        if ($activity->isDirty('remind_at')) {
            $activity->reminded_at = null;
        }

        foreach (array_keys(self::LINKS) as $index => $key) {
            if (array_key_exists($key, $data)) {
                $activity->{$key} = $linked[$index]?->getKey();
            }
        }

        if (array_key_exists('party_id', $data)) {
            $activity->party_id = $data['party_id'] === null ? null : $this->party($activity->company_id, (string) $data['party_id']);
        } elseif ($activity->party_id === null) {
            // A lead's customer (once converted), an opportunity's or a quote's.
            $activity->party_id = collect($linked)->filter()->map(fn (ScopedRecord $record) => $record->getAttribute('party_id'))->filter()->first();
        }

        if (array_key_exists('email', $data)) {
            $activity->email = $data['email'] === null ? null : ['to' => $data['email']['to'] ?? null, 'subject' => $data['email']['subject'] ?? null, 'sent' => 'logged'];
        }
    }

    /**
     * The linked lead, opportunity and quote (visible to $by, else 422).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?ScopedRecord, 1: ?ScopedRecord, 2: ?ScopedRecord}
     */
    private function linked(array $data, User $by): array
    {
        $linked = [];

        foreach (self::LINKS as $key => [$model, $resource]) {
            $id = $data[$key] ?? null;
            $record = is_string($id) && Str::isUuid($id) ? $model::query()->find($id) : null;

            if ($id !== null && ($record === null || ! $this->access->sees($by, $record, $resource))) {
                throw CrmError::make('link_unknown', field: $key);
            }

            $linked[] = $record;
        }

        return $linked;
    }

    private function party(string $companyId, string $partyId): string
    {
        return Party::query()->whereKey($partyId)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->value('id')
            ?? throw CrmError::make('party_unknown', field: 'party_id');
    }

    /** The owner: oneself, or (with a team or branch scope at the branch) someone who may view activities there. */
    private function owner(Activity $activity, string $ownerId, User $by): string
    {
        if ($ownerId !== $by->id && ! $this->access->canAt($by, CrmAccess::SCOPE_BRANCH, $activity->branch_id) && ! $this->access->canAt($by, CrmAccess::SCOPE_TEAM, $activity->branch_id)) {
            throw new AuthorizationException(__('crm.errors.assign_forbidden'));
        }

        return $this->records->owner($ownerId, $activity->branch_id, 'activity')->id;
    }
}
