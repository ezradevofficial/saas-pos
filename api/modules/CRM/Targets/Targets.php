<?php

namespace Modules\CRM\Targets;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\VisibleScope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Accounting\Contracts\InvoiceTotals;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Target;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;
use Modules\CRM\Support\CrmAccess;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Tx;
use Modules\POS\Contracts\SalesTotals;

/**
 * CRM-30: sales targets and their actuals, computed when read (decision 7),
 * in the company's base currency, the period's days in its time zone:
 *
 * - `won_value`: won opportunities' base value by close date, of the
 *   user (as owner), of the team's members and leader, or at the branch;
 *   none for a reader whose field rules hide opportunity values (CRM-09);
 * - `pos_sales`: POS sales less refunds by those cashiers or at the
 *   branch (`POS\Contracts\SalesTotals`, only with POS active and for a
 *   reader holding `pos.sale.view` at the target's place);
 * - `invoiced`: posted customer invoices less credit notes created by
 *   those users or at the branch (`Accounting\Contracts\InvoiceTotals`,
 *   only with Accounting active and for a reader holding
 *   `accounting.invoice.view` at the target's company).
 *
 * Without one, the actual is null and `unavailable` says why
 * (`module_inactive`, `not_permitted`, `field_hidden`).
 *
 * A user sees, in companies where they hold `crm.target.view` or
 * `crm.target.manage`: their own targets; those at a place (the branch,
 * or the company for a company-wide target) where they hold
 * `crm.target.manage` or `crm.scope.branch` with a target permission;
 * and their teams' at a place where they hold `crm.scope.team` with a
 * target permission. Filtered in SQL. Saving needs `crm.target.manage`
 * at the target's place before and after the change.
 */
class Targets
{
    public function __construct(private readonly CrmAccess $access) {}

    /** @param array<string, mixed> $data */
    public function save(?Target $target, array $data, User $by): Target
    {
        return Tx::run(function () use ($target, $data, $by) {
            $company = $target === null ? Company::query()->findOrFail($data['company_id']) : Company::query()->findOrFail($target->company_id);
            $target ??= new Target(['company_id' => $company->id, 'currency' => $company->base_currency, 'created_by' => $by->id]);

            foreach (['metric', 'period_start', 'period_end'] as $key) {
                if (array_key_exists($key, $data)) {
                    $target->{$key} = $data[$key];
                }
            }

            if (array_key_exists('amount_minor', $data)) {
                $target->amount_minor = (int) $data['amount_minor'];
            }

            if (array_key_exists('branch_id', $data)) {
                $target->branch_id = $data['branch_id'] === null ? null : $this->branch($company->id, (string) $data['branch_id'])->id;
            }

            if (array_key_exists('subject_type', $data) || array_key_exists('subject_id', $data)) {
                $target->subject_type = $data['subject_type'] ?? $target->subject_type;
                $target->subject_id = $this->subject($company, (string) $target->subject_type, (string) ($data['subject_id'] ?? $target->subject_id));
            }

            // A branch target is at its branch.
            if ($target->subject_type === 'branch') {
                $target->branch_id = $target->subject_id;
            }

            if ($target->period_end->lessThan($target->period_start)) {
                throw CrmError::make('period_invalid', field: 'period_end');
            }

            // CRM-30 (review): the place the target ends up at needs the right too.
            if (! $this->mayManage($by, $target)) {
                throw new AuthorizationException(__('rbac.forbidden'));
            }

            $target->save();

            return $target->refresh();
        });
    }

    /** The targets $user sees, newest periods first (CRM-30), as a query. */
    public function visibleQuery(User $user, ?string $companyId = null, bool $archived = false): Builder
    {
        $view = $this->access->scopeOf($user, 'crm.target.view');
        $manage = $this->access->scopeOf($user, 'crm.target.manage');
        $branchScope = $this->access->scopeOf($user, CrmAccess::SCOPE_BRANCH);
        $teamScope = $this->access->scopeOf($user, CrmAccess::SCOPE_TEAM);
        $companies = $this->access->companies($user, 'crm.target.view', 'crm.target.manage');
        $teams = $this->access->teamIds($user);

        $query = Target::query()->with(['company', 'branch'])->orderByDesc('period_start')->orderBy('created_at')->orderBy('id');

        if ($companies !== null) {
            $query->whereIn('company_id', $companies);
        }

        $query->where(function (Builder $q) use ($user, $view, $manage, $branchScope, $teamScope, $teams) {
            $q->where(fn (Builder $q) => $q->where('subject_type', 'user')->where('subject_id', $user->id));
            $q->orWhere(fn (Builder $q) => $this->atPlace($q, $manage));
            $q->orWhere(fn (Builder $q) => $this->atPlace($q, $branchScope)->where(fn (Builder $q) => $this->atPlace($q, $view)->orWhere(fn (Builder $q) => $this->atPlace($q, $manage))));

            if ($teams !== []) {
                $q->orWhere(fn (Builder $q) => $q->where('subject_type', 'team')->whereIn('subject_id', $teams)
                    ->where(fn (Builder $q) => $this->atPlace($q, $teamScope))
                    ->where(fn (Builder $q) => $this->atPlace($q, $view)->orWhere(fn (Builder $q) => $this->atPlace($q, $manage))));
            }
        });

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if (! $archived) {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    /** $q limited to targets whose place (branch, or company when company-wide) is in $scope. */
    private function atPlace(Builder $q, VisibleScope $scope): Builder
    {
        if ($scope->all) {
            return $q->whereRaw('true');
        }

        return $q->where(function (Builder $q) use ($scope) {
            $q->whereIn('branch_id', $scope->branchIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $scope->branchIds)
                ->orWhere(fn (Builder $q) => $q->whereNull('branch_id')->whereIn('company_id', $scope->companyIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $scope->companyIds));
        });
    }

    public function sees(User $user, Target $target): bool
    {
        return $this->visibleQuery($user, archived: true)->whereKey($target->id)->exists();
    }

    public function mayManage(User $user, Target $target): bool
    {
        return $this->at($user, 'crm.target.manage', $target);
    }

    /** Whether $user holds $permission at the target's place. */
    private function at(User $user, string $permission, Target $target): bool
    {
        return $target->branch_id !== null
            ? $this->access->canAt($user, $permission, $target->branch_id)
            : $this->access->canAtCompany($user, $permission, $target->company_id);
    }

    /**
     * The target's actual in its currency (minor units) and the share of the
     * amount reached (a percent with one decimal), for $reader; nulls with
     * the reason when there is none for them.
     *
     * @param  array<string, list<string>>  $members  team id => member ids, already read (batched)
     * @return array{actual_minor: ?string, percent: ?string, available: bool, unavailable: ?string}
     */
    public function actual(Target $target, ?User $reader = null, array $members = []): array
    {
        $none = fn (string $reason) => ['actual_minor' => null, 'percent' => null, 'available' => false, 'unavailable' => $reason];
        $from = $target->period_start->toDateString();
        $to = $target->period_end->toDateString();
        $users = match ($target->subject_type) {
            'user' => [$target->subject_id],
            'team' => $members[$target->subject_id] ?? $this->members([$target->subject_id])[$target->subject_id] ?? [],
            default => null,
        };
        $branch = $target->subject_type === 'branch' ? $target->subject_id : null;

        if ($target->metric === Target::WON_VALUE && $reader !== null && $this->access->hidesValues($reader)) {
            return $none('field_hidden');
        }

        if ($target->metric === Target::POS_SALES && $reader !== null && class_exists(SalesTotals::class)
            && ! $this->at($reader, 'pos.sale.view', $target)) {
            return $none('not_permitted');
        }

        if ($target->metric === Target::INVOICED && $reader !== null && class_exists(InvoiceTotals::class)
            && ! $reader->can('accounting.invoice.view', Scope::company($target->company_id))) {
            return $none('not_permitted');
        }

        $actual = match ($target->metric) {
            Target::WON_VALUE => (string) Opportunity::query()->where('company_id', $target->company_id)->where('status', Opportunity::WON)
                ->whereBetween('closed_on', [$from, $to])
                ->when($users !== null, fn ($q) => $q->whereIn('owner_id', $users === [] ? ['00000000-0000-0000-0000-000000000000'] : $users))
                ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))
                ->sum('base_value_minor'),
            Target::POS_SALES => class_exists(SalesTotals::class) ? app(SalesTotals::class)->net($target->company_id, $from, $to, $users, $branch) : null,
            Target::INVOICED => class_exists(InvoiceTotals::class) ? app(InvoiceTotals::class)->posted($target->company_id, $from, $to, $users, $branch) : null,
        };

        if ($actual === null) {
            return $none('module_inactive');
        }

        $percent = $target->amount_minor === 0 ? null
            : (string) BigDecimal::of($actual)->multipliedBy(100)->dividedBy($target->amount_minor, 1, RoundingMode::HalfUp);

        return ['actual_minor' => $actual, 'percent' => $percent, 'available' => true, 'unavailable' => null];
    }

    /**
     * Each team's members and leader, in two queries.
     *
     * @param  list<string>  $teamIds
     * @return array<string, list<string>>
     */
    public function members(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $members = array_fill_keys($teamIds, []);

        foreach (TeamMember::query()->whereIn('team_id', $teamIds)->get(['team_id', 'user_id']) as $row) {
            $members[$row->team_id][] = $row->user_id;
        }

        foreach (Team::query()->whereKey($teamIds)->whereNotNull('leader_id')->get(['id', 'leader_id']) as $team) {
            $members[$team->id][] = $team->leader_id;
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $members);
    }

    private function branch(string $companyId, string $branchId): Branch
    {
        return Branch::query()->where('company_id', $companyId)->whereKey($branchId)->first()
            ?? throw CrmError::make('branch_not_of_company', field: 'branch_id');
    }

    private function subject(Company $company, string $type, string $id): string
    {
        $found = Str::isUuid($id) ? match ($type) {
            'user' => User::query()->whereKey($id)->where('status', User::STATUS_ACTIVE)->value('id'),
            'team' => Team::query()->where('company_id', $company->id)->whereKey($id)->whereNull('archived_at')->value('id'),
            'branch' => Branch::query()->where('company_id', $company->id)->whereKey($id)->value('id'),
            default => null,
        } : null;

        return $found ?? throw CrmError::make('subject_unknown', field: 'subject_id');
    }
}
