<?php

namespace App\Core\Configuration;

use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;

/**
 * Where configuration is resolved (LAY-06): a company, optionally a branch
 * of it and a location of that branch. Built from the most specific id
 * given; the levels above are read from the database (row-level security
 * keeps them in the tenant).
 */
final class Place
{
    public function __construct(
        public readonly string $companyId,
        public readonly ?string $branchId = null,
        public readonly ?string $locationId = null,
    ) {}

    /**
     * The place named by the ids, or null when none is given. False when
     * an id is not of this tenant or the ids disagree (a branch of another
     * company).
     */
    public static function from(?string $companyId, ?string $branchId, ?string $locationId): self|false|null
    {
        if ($locationId !== null) {
            $location = Location::query()->with('branch')->find($locationId);

            if ($location === null || ($branchId !== null && $branchId !== $location->branch_id)
                || ($companyId !== null && $companyId !== $location->branch->company_id)) {
                return false;
            }

            return new self($location->branch->company_id, $location->branch_id, $location->id);
        }

        if ($branchId !== null) {
            $branch = Branch::query()->find($branchId);

            if ($branch === null || ($companyId !== null && $companyId !== $branch->company_id)) {
                return false;
            }

            return new self($branch->company_id, $branch->id);
        }

        return $companyId === null ? null : new self($companyId);
    }

    /** The most specific scope of the place. */
    public function scope(): Scope
    {
        return match (true) {
            $this->locationId !== null => Scope::location($this->locationId),
            $this->branchId !== null => Scope::branch($this->branchId),
            default => Scope::company($this->companyId),
        };
    }
}
