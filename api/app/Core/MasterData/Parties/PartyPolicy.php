<?php

namespace App\Core\MasterData\Parties;

use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;

/**
 * RBAC-04, TEN-08 for parties (ADR 006, "Shared master data"):
 *
 * - A shared party (no company) is reached by holders of the permission at
 *   any scope of the tenant: a cashier with `core.party.view` at a location
 *   sees the group's shared customers.
 * - A company's party is reached by holders whose scope touches that
 *   company: the company itself, or one of its branches or locations.
 *
 * The same "touched" rule answers view, create, edit and archive. A party
 * the user reaches with none of the party permissions is not found (404);
 * one reached without the action's permission is forbidden (403).
 */
class PartyPolicy
{
    public const PERMISSIONS = ['core.party.view', 'core.party.create', 'core.party.edit', 'core.party.archive'];

    public function __construct(private readonly CompanyReach $reach) {}

    /** Reached with any party permission: otherwise the party is not found. */
    public function reach(User $user, Party $party): bool
    {
        return $this->sameTenant($user, $party) && $this->reach->reachesRecord($user, $party->company_id, self::PERMISSIONS);
    }

    public function view(User $user, Party $party): bool
    {
        return $this->sameTenant($user, $party) && $this->reach->reachesRecord($user, $party->company_id, ['core.party.view']);
    }

    public function viewAny(User $user): bool
    {
        return $this->reach->anywhere($user, ['core.party.view']);
    }

    /** Create a party with $companyId (null: shared). */
    public function create(User $user, ?string $companyId = null): bool
    {
        return $this->reach->reachesRecord($user, $companyId, ['core.party.create']);
    }

    public function update(User $user, Party $party): bool
    {
        return $this->sameTenant($user, $party) && $this->reach->reachesRecord($user, $party->company_id, ['core.party.edit']);
    }

    /** Move a party to $companyId (null: shared) on edit. */
    public function editIn(User $user, ?string $companyId): bool
    {
        return $this->reach->reachesRecord($user, $companyId, ['core.party.edit']);
    }

    public function archive(User $user, Party $party): bool
    {
        return $this->sameTenant($user, $party) && $this->reach->reachesRecord($user, $party->company_id, ['core.party.archive']);
    }

    public function restore(User $user, Party $party): bool
    {
        return $this->archive($user, $party);
    }

    /** Companies whose parties the user may list, or null for all. */
    public function listableCompanies(User $user): ?array
    {
        return $this->reach->companyIds($user, ['core.party.view']);
    }

    private function sameTenant(User $user, Party $party): bool
    {
        return $party->tenant_id === $user->tenant_id;
    }
}
