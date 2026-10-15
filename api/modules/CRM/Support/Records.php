<?php

namespace Modules\CRM\Support;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Support\Str;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;

/**
 * The core records CRM documents name, checked against the document's
 * company (row-level security already hides other tenants'): branches,
 * customers (MD-01, shared or of the company), active currencies
 * (CUR-01), owners (CRM-10) and teams (CRM-04).
 */
class Records
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    /** An active branch, with its company. */
    public function branch(string $branchId, string $field = 'branch_id'): Branch
    {
        $branch = Str::isUuid($branchId) ? Branch::query()->with('company')->whereKey($branchId)->whereNull('archived_at')->first() : null;

        return $branch ?? throw CrmError::make('branch_unknown', field: $field);
    }

    /** MD-01: a customer party of the company or shared, not archived. */
    public function customer(string $companyId, string $partyId, string $field = 'party_id'): Party
    {
        $party = Str::isUuid($partyId) ? Party::query()->whereKey($partyId)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->first() : null;

        if ($party === null || ! in_array('customer', $party->roles ?? [], true)) {
            throw CrmError::make('party_not_customer', field: $field);
        }

        return $party;
    }

    /** CUR-01: a currency the tenant has active. */
    public function currency(string $code, string $field = 'currency'): string
    {
        $code = strtoupper($code);

        if (! TenantCurrency::query()->where('code', $code)->where('active', true)->exists()) {
            throw CrmError::make('currency_inactive', ['currency' => $code], $field);
        }

        return $code;
    }

    /** CRM-10: an active user who may view $resource records at the branch. */
    public function owner(string $userId, string $branchId, string $resource, string $field = 'owner_id'): User
    {
        $user = Str::isUuid($userId) ? User::query()->find($userId) : null;

        if ($user === null || ! $this->eligible($user, $branchId, $resource)) {
            throw CrmError::make('owner_not_eligible', field: $field);
        }

        return $user;
    }

    public function eligible(User $user, string $branchId, string $resource): bool
    {
        return $user->isActive() && $this->resolver->can($user, "crm.{$resource}.view", Scope::branch($branchId));
    }

    /** CRM-04: an active team of the company, usable at the branch (a team of another branch is not). */
    public function team(string $companyId, ?string $branchId, string $teamId, string $field = 'team_id'): Team
    {
        $team = Str::isUuid($teamId) ? Team::query()->where('company_id', $companyId)->whereKey($teamId)->whereNull('archived_at')->first() : null;

        if ($team === null || ($branchId !== null && $team->branch_id !== null && $team->branch_id !== $branchId)) {
            throw CrmError::make('team_unknown', field: $field);
        }

        return $team;
    }

    /** The owner's only team at the branch, when they have exactly one (null otherwise). */
    public function soleTeam(string $companyId, string $branchId, string $userId): ?string
    {
        $teams = Team::query()->where('company_id', $companyId)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->where(fn ($q) => $q->where('leader_id', $userId)->orWhereIn('id', TeamMember::query()->where('user_id', $userId)->select('team_id')))
            ->pluck('id')->all();

        return count($teams) === 1 ? $teams[0] : null;
    }
}
