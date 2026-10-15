<?php

namespace App\Core\Limits;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use InvalidArgumentException;

/**
 * LIM-01: the current tenant's active units, under row-level security:
 * - outlets: locations of type `outlet` that are not archived;
 * - devices: paired devices that are active (not pending, suspended or unpaired);
 * - users: active users holding a back-office role (an active role with at
 *   least one permission, at any scope); self-service sign-ins (employees
 *   linked to a user with no such role) do not count (owner decision
 *   2026-10-14, docs/modules/hr.md);
 * - employees: from HR (EmployeeCount; none until HR exists).
 */
class UsageCounter
{
    public function __construct(private readonly EmployeeCount $employees) {}

    /** @return array<string, int> unit => count */
    public function all(): array
    {
        return array_combine(Units::ALL, array_map(fn (string $unit) => $this->count($unit), Units::ALL));
    }

    public function count(string $unit): int
    {
        return match ($unit) {
            Units::OUTLET => Location::query()->where('type', 'outlet')->whereNull('archived_at')->count(),
            Units::DEVICE => Device::query()->where('status', Device::STATUS_ACTIVE)->count(),
            Units::USER => User::query()->where('status', User::STATUS_ACTIVE)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('role_assignments')
                    ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
                    ->whereColumn('role_assignments.user_id', 'users.id')
                    ->whereNull('roles.archived_at')
                    ->whereExists(fn ($p) => $p->selectRaw('1')->from('role_has_permissions')->whereColumn('role_has_permissions.role_id', 'roles.id')))
                ->count(),
            Units::EMPLOYEE => $this->employees->count(),
            default => throw new InvalidArgumentException("Unknown unit [{$unit}]."),
        };
    }
}
