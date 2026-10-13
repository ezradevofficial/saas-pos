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
 * - users: active users (not pending invitations or deactivated);
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
            Units::USER => User::query()->where('status', User::STATUS_ACTIVE)->count(),
            Units::EMPLOYEE => $this->employees->count(),
            default => throw new InvalidArgumentException("Unknown unit [{$unit}]."),
        };
    }
}
