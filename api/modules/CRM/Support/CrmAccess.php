<?php

namespace Modules\CRM\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Modules\CRM\Models\ScopedRecord;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;

/**
 * CRM-08 (RBAC-04, RBAC-07): who sees and changes CRM records.
 *
 * Leads, opportunities, quotes and activities live at a branch with an
 * owner and an optional team. A user holding `crm.{resource}.view` at the
 * branch (or above) sees:
 *
 * - the records they own;
 * - with `crm.scope.team` there, the records of their teams (as a member
 *   or the leader);
 * - with `crm.scope.branch` there, every record at the branch, unassigned
 *   ones included.
 *
 * A change needs the action's permission at the branch and the record to
 * be visible. Lists, search, exports, the calendar, the 360 view, targets
 * and dashboards all filter with visible().
 */
class CrmAccess
{
    public const SCOPE_TEAM = 'crm.scope.team';

    public const SCOPE_BRANCH = 'crm.scope.branch';

    /** @var array<string, list<string>> per request, the teams of a user */
    private array $teams = [];

    public function __construct(private readonly ScopeResolver $resolver) {}

    /** $query (a scoped record's table) limited to what $user may see of $resource. */
    public function visible(Builder $query, User $user, string $resource): Builder
    {
        $view = $this->resolver->visibleIds($user, "crm.{$resource}.view");
        $branch = $this->resolver->visibleIds($user, self::SCOPE_BRANCH);
        $team = $this->resolver->visibleIds($user, self::SCOPE_TEAM);
        $teams = $this->teamIds($user);
        $column = fn (string $name) => $query->qualifyColumn($name);

        if (! $view->all && $view->branchIds === []) {
            return $query->whereRaw('false');
        }

        if (! $view->all) {
            $query->whereIn($column('branch_id'), $view->branchIds);
        }

        return $query->where(function (Builder $q) use ($user, $branch, $team, $teams, $column) {
            $q->where($column('owner_id'), $user->id);

            if ($branch->all) {
                $q->orWhereRaw('true');

                return;
            }

            if ($branch->branchIds !== []) {
                $q->orWhereIn($column('branch_id'), $branch->branchIds);
            }

            if ($teams !== [] && ($team->all || $team->branchIds !== [])) {
                $q->orWhere(function (Builder $q) use ($team, $teams, $column) {
                    $q->whereIn($column('team_id'), $teams);

                    if (! $team->all) {
                        $q->whereIn($column('branch_id'), $team->branchIds);
                    }
                });
            }
        });
    }

    /** Whether $user sees $record as a $resource (CRM-08). */
    public function sees(User $user, ScopedRecord $record, string $resource): bool
    {
        $branch = Scope::branch($record->branch_id);

        if (! $this->resolver->can($user, "crm.{$resource}.view", $branch)) {
            return false;
        }

        if ($record->owner_id !== null && $record->owner_id === $user->id) {
            return true;
        }

        if ($this->resolver->can($user, self::SCOPE_BRANCH, $branch)) {
            return true;
        }

        return $record->team_id !== null && in_array($record->team_id, $this->teamIds($user), true)
            && $this->resolver->can($user, self::SCOPE_TEAM, $branch);
    }

    /** Whether $user sees $record and holds $permission at its branch. */
    public function may(User $user, string $permission, ScopedRecord $record, string $resource): bool
    {
        return $this->sees($user, $record, $resource) && $this->resolver->can($user, $permission, Scope::branch($record->branch_id));
    }

    /** 403 unless $user holds $permission at the branch. */
    public function assertAt(User $user, string $permission, string $branchId): void
    {
        if (! $this->resolver->can($user, $permission, Scope::branch($branchId))) {
            throw new AuthorizationException(__('rbac.forbidden'));
        }
    }

    public function canAt(User $user, string $permission, string $branchId): bool
    {
        return $this->resolver->can($user, $permission, Scope::branch($branchId));
    }

    public function canAtCompany(User $user, string $permission, string $companyId): bool
    {
        return $this->resolver->can($user, $permission, Scope::company($companyId));
    }

    /** Whether $user holds $permission anywhere. */
    public function anywhere(User $user, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->resolver->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The companies where $user holds any of $permissions at, above or
     * beneath the company (null: every company, a tenant-wide role).
     *
     * @return list<string>|null
     */
    public function companies(User $user, string ...$permissions): ?array
    {
        $ids = [];

        foreach ($permissions as $permission) {
            $touched = $this->resolver->visibleIds($user, $permission)->companiesTouched();

            if ($touched === null) {
                return null;
            }

            array_push($ids, ...$touched);
        }

        return array_values(array_unique($ids));
    }

    /** Where $user holds $permission. */
    public function scopeOf(User $user, string $permission): VisibleScope
    {
        return $this->resolver->visibleIds($user, $permission);
    }

    /** The active teams $user is in, as a member or the leader. @return list<string> */
    public function teamIds(User $user): array
    {
        return $this->teams[$user->id] ??= array_values(array_unique([
            ...TeamMember::query()->where('user_id', $user->id)
                ->whereIn('team_id', Team::query()->whereNull('archived_at')->select('id'))->pluck('team_id')->all(),
            ...Team::query()->where('leader_id', $user->id)->whereNull('archived_at')->pluck('id')->all(),
        ]));
    }
}
