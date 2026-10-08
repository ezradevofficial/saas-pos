<?php

namespace App\Core\MasterData;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * RBAC-04, TEN-08 for sharable master data (ADR 006, "Shared master
 * data"): parties, items, item categories. Records have `company_id` null
 * (shared) or a company.
 *
 * - A shared record is reached by holders of the permission at any scope
 *   of the tenant: a cashier with `view` at a location sees the group's
 *   shared records.
 * - A company's record is reached by holders whose scope touches that
 *   company: the company itself, or one of its branches or locations.
 *
 * View and create follow that "touched" rule. Edit and archive of a
 * company's record need the permission at a scope covering the company (a
 * company or tenant assignment): a branch user reads and adds the company's
 * records but does not change them. Shared records are changed by holders
 * of the permission at any scope. A record the user reaches with none of
 * the resource's permissions is not found (404); one reached without the
 * action's permission is forbidden (403).
 */
abstract class SharedRecordPolicy
{
    public function __construct(
        protected readonly CompanyReach $reach,
        protected readonly ScopeResolver $resolver,
    ) {}

    /** The permission prefix, e.g. `core.party`. */
    abstract protected function resource(): string;

    /** @return list<string> view, create, edit and archive */
    public function permissions(): array
    {
        return array_map(fn (string $action) => "{$this->resource()}.{$action}", ['view', 'create', 'edit', 'archive']);
    }

    /** Reached with any of the resource's permissions: otherwise the record is not found. */
    public function reach(User $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $this->reach->reachesRecord($user, $record->company_id, $this->permissions());
    }

    public function view(User $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $this->reach->reachesRecord($user, $record->company_id, ["{$this->resource()}.view"]);
    }

    public function viewAny(User $user): bool
    {
        return $this->reach->anywhere($user, ["{$this->resource()}.view"]);
    }

    /** Create a record with $companyId (null: shared). */
    public function create(User $user, ?string $companyId = null): bool
    {
        return $this->reach->reachesRecord($user, $companyId, ["{$this->resource()}.create"]);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $this->covers($user, "{$this->resource()}.edit", $record->company_id);
    }

    /** Move a record to $companyId (null: shared) on edit: `edit` covering the new company. */
    public function editIn(User $user, ?string $companyId): bool
    {
        return $this->covers($user, "{$this->resource()}.edit", $companyId);
    }

    public function archive(User $user, Model $record): bool
    {
        return $this->sameTenant($user, $record) && $this->covers($user, "{$this->resource()}.archive", $record->company_id);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->archive($user, $record);
    }

    /** Companies whose records the user may list, or null for all. */
    public function listableCompanies(User $user): ?array
    {
        return $this->reach->companyIds($user, ["{$this->resource()}.view"]);
    }

    /**
     * Changing a company's record needs the permission at a scope covering
     * that company (a company or tenant assignment); a shared record, the
     * permission anywhere.
     */
    protected function covers(User $user, string $permission, ?string $companyId): bool
    {
        return $companyId === null
            ? $this->resolver->can($user, $permission)
            : $this->resolver->can($user, $permission, Scope::company($companyId));
    }

    protected function sameTenant(User $user, Model $record): bool
    {
        return $record->tenant_id === $user->tenant_id;
    }
}
