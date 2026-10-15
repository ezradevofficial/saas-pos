<?php

namespace Modules\CRM\Targets;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
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
 * - `pos_sales`: POS sales less refunds by those cashiers or at the
 *   branch (`POS\Contracts\SalesTotals`, only with POS active);
 * - `invoiced`: posted customer invoices less credit notes created by
 *   those users or at the branch (`Accounting\Contracts\InvoiceTotals`,
 *   only with Accounting active).
 *
 * A metric whose module is off has no actual (`available: false`).
 * A user sees the targets of companies where they hold
 * `crm.target.view`: their own, their teams' (`crm.scope.team`), the
 * branch's and everyone's at a branch where they hold `crm.scope.branch`
 * (company-wide targets: at the company), and all with
 * `crm.target.manage` there.
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

            $target->save();

            return $target->refresh();
        });
    }

    /** @return Collection<int, Target> the targets $user sees, newest periods first */
    public function visible(User $user, ?string $companyId = null, bool $archived = false): Collection
    {
        $companies = $this->access->companies($user, 'crm.target.view', 'crm.target.manage');
        $query = Target::query()->with(['company', 'branch'])->orderByDesc('period_start')->orderBy('created_at');

        if ($companies !== null) {
            $query->whereIn('company_id', $companies);
        }

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if (! $archived) {
            $query->whereNull('archived_at');
        }

        return $query->get()->filter(fn (Target $target) => $this->sees($user, $target))->values();
    }

    public function sees(User $user, Target $target): bool
    {
        $place = fn (string $permission) => $target->branch_id !== null
            ? $this->access->canAt($user, $permission, $target->branch_id)
            : $this->access->canAtCompany($user, $permission, $target->company_id);

        // One's own target, wherever in its company one may view targets.
        if ($target->subject_type === 'user' && $target->subject_id === $user->id) {
            $companies = $this->access->companies($user, 'crm.target.view');

            return $companies === null || in_array($target->company_id, $companies, true);
        }

        if (! $place('crm.target.view') && ! $place('crm.target.manage')) {
            return false;
        }

        return $place('crm.target.manage') || $place(CrmAccess::SCOPE_BRANCH)
            || ($target->subject_type === 'team' && in_array($target->subject_id, $this->access->teamIds($user), true) && $place(CrmAccess::SCOPE_TEAM));
    }

    public function mayManage(User $user, Target $target): bool
    {
        return $target->branch_id !== null
            ? $this->access->canAt($user, 'crm.target.manage', $target->branch_id)
            : $this->access->canAtCompany($user, 'crm.target.manage', $target->company_id);
    }

    /**
     * The target's actual in its currency (minor units) and the share of the
     * amount reached (a percent with one decimal), or nulls when the metric
     * is not available.
     *
     * @return array{actual_minor: ?string, percent: ?string, available: bool}
     */
    public function actual(Target $target): array
    {
        $from = $target->period_start->toDateString();
        $to = $target->period_end->toDateString();
        $users = match ($target->subject_type) {
            'user' => [$target->subject_id],
            'team' => $this->members($target->subject_id),
            default => null,
        };
        $branch = $target->subject_type === 'branch' ? $target->subject_id : null;

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
            return ['actual_minor' => null, 'percent' => null, 'available' => false];
        }

        $percent = $target->amount_minor === 0 ? null
            : (string) BigDecimal::of($actual)->multipliedBy(100)->dividedBy($target->amount_minor, 1, RoundingMode::HalfUp);

        return ['actual_minor' => $actual, 'percent' => $percent, 'available' => true];
    }

    /** @return list<string> the team's members and leader */
    private function members(string $teamId): array
    {
        return array_values(array_unique(array_filter([
            ...TeamMember::query()->where('team_id', $teamId)->pluck('user_id')->all(),
            Team::query()->whereKey($teamId)->value('leader_id'),
        ])));
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
