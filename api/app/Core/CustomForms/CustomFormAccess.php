<?php

namespace App\Core\CustomForms;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who works with custom form records (CF-04, RBAC-04; docs/adr/012).
 *
 * One generic set of permissions serves every form type, because the
 * catalogue is global and form types are tenant data:
 * `core.custom_form.view|create|edit|approve`, held at the record's place
 * (a company role covers its branches and locations, a branch role its
 * locations). A form type may name the roles that use it (`role_ids`): then
 * the user must also hold one of those roles at a scope covering the
 * record. An empty list means everyone holding the permission. Owners pass
 * the list (RBAC-10: the organisation is never locked out of its records).
 *
 * - view: `view` (or `edit`) at the record's place;
 * - create: `create` at the place chosen for the record;
 * - edit a draft, submit or cancel it: its creator holding `create` there,
 *   or a holder of `edit` there;
 * - archive and restore: `edit` there;
 * - approve: the workflow's own rules (approvers, `approve` for stages
 *   naming no roles; WF-08).
 *
 * Form types are managed with `core.custom_form_type.manage` at tenant scope.
 */
class CustomFormAccess
{
    public const VIEW = 'core.custom_form.view';

    public const CREATE = 'core.custom_form.create';

    public const EDIT = 'core.custom_form.edit';

    public const APPROVE = 'core.custom_form.approve';

    public const MANAGE = 'core.custom_form_type.manage';

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly OwnerGuard $owners,
    ) {}

    public function manages(?User $user): bool
    {
        return $user !== null && $this->resolver->can($user, self::MANAGE, Scope::tenant());
    }

    /** Whether the user may use the type anywhere for $permissions (the nav, the list). */
    public function anywhere(User $user, CustomFormType $type, array $permissions = [self::VIEW, self::EDIT]): bool
    {
        if ($type->isArchived() && ! $this->manages($user)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($this->resolver->can($user, $permission) && $this->allowedAnywhere($user, $type)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the user holds $permission for the type at $scope (and an allowed role there). */
    public function at(User $user, CustomFormType $type, string $permission, Scope $scope): bool
    {
        return $this->resolver->can($user, $permission, $scope) && $this->allowedAt($user, $type, $scope);
    }

    public function view(User $user, CustomFormRecord $record): bool
    {
        $type = $record->type;

        return $record->tenant_id === $user->tenant_id
            && ($this->at($user, $type, self::VIEW, $record->scope()) || $this->at($user, $type, self::EDIT, $record->scope()));
    }

    public function edit(User $user, CustomFormRecord $record): bool
    {
        return $this->view($user, $record) && (
            $this->at($user, $record->type, self::EDIT, $record->scope())
            || ($record->created_by === $user->id && $this->at($user, $record->type, self::CREATE, $record->scope()))
        );
    }

    public function manageRecord(User $user, CustomFormRecord $record): bool
    {
        return $this->view($user, $record) && $this->at($user, $record->type, self::EDIT, $record->scope());
    }

    /** RBAC-04: the type's records the user may see. */
    public function visible(Builder $query, User $user, CustomFormType $type): Builder
    {
        $query->where($query->qualifyColumn('type_id'), $type->id);
        $view = $this->resolver->visibleIds($user, self::VIEW);
        $edit = $this->resolver->visibleIds($user, self::EDIT);

        if (! $view->all && ! $edit->all) {
            $query->where(fn (Builder $q) => $q->where(fn (Builder $v) => $this->within($v, $view))->orWhere(fn (Builder $e) => $this->within($e, $edit)));
        }

        if (! $this->unlisted($user, $type)) {
            $this->within($query, $this->roleScope($user, $type->role_ids));
        }

        return $query;
    }

    /** Records at a place the scope covers: the location, else the branch, else the company. */
    private function within(Builder $query, VisibleScope $scope): Builder
    {
        if ($scope->all) {
            return $query;
        }

        $column = fn (string $name) => $query->qualifyColumn($name);

        return $query->where(fn (Builder $q) => $q
            ->whereRaw('false')
            ->when($scope->locationIds !== [], fn (Builder $l) => $l->orWhereIn($column('location_id'), $scope->locationIds))
            ->when($scope->branchIds !== [], fn (Builder $l) => $l->orWhere(fn (Builder $b) => $b->whereNull($column('location_id'))->whereIn($column('branch_id'), $scope->branchIds)))
            ->when($scope->companyIds !== [], fn (Builder $l) => $l->orWhere(fn (Builder $c) => $c->whereNull($column('branch_id'))->whereIn($column('company_id'), $scope->companyIds))));
    }

    /** No allow-list applies: the type names no roles, or the user is an Owner. */
    private function unlisted(User $user, CustomFormType $type): bool
    {
        return ($type->role_ids ?? []) === [] || $this->owners->isOwner($user);
    }

    private function allowedAnywhere(User $user, CustomFormType $type): bool
    {
        return $this->unlisted($user, $type) || array_intersect($this->resolver->roleIds($user), $type->role_ids) !== [];
    }

    private function allowedAt(User $user, CustomFormType $type, Scope $scope): bool
    {
        return $this->unlisted($user, $type) || array_intersect($this->resolver->roleIds($user, $scope), $type->role_ids) !== [];
    }

    /** Where the user holds one of $roleIds, expanded downwards (as ScopeResolver::visibleIds). */
    private function roleScope(User $user, array $roleIds): VisibleScope
    {
        $rows = RoleAssignment::query()
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->whereNull('roles.archived_at')
            ->where('role_assignments.user_id', $user->id)
            ->whereIn('role_assignments.role_id', $roleIds)
            ->toBase()
            ->get(['role_assignments.scope_type', 'role_assignments.scope_id']);

        if ($rows->contains('scope_type', Scope::TENANT)) {
            return new VisibleScope(all: true);
        }

        $ids = fn (string $type) => $rows->where('scope_type', $type)->pluck('scope_id')->unique()->values()->all();
        $companies = $ids(Scope::COMPANY);
        $branches = array_values(array_unique([...$ids(Scope::BRANCH), ...($companies === [] ? [] : Branch::query()->whereIn('company_id', $companies)->pluck('id')->all())]));
        $locations = array_values(array_unique([...$ids(Scope::LOCATION), ...($branches === [] ? [] : Location::query()->whereIn('branch_id', $branches)->pluck('id')->all())]));

        return new VisibleScope(false, $companies, $branches, $locations);
    }
}
