<?php

namespace Modules\Hotel\Support;

use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Hotel\Models\Property;

/**
 * HOT-30, TEN-03: a property is a branch. Reads a branch under RLS (another
 * tenant's branch is not found) and keeps its business date, which starts at
 * the branch's local today the first time it is needed and then moves only
 * with the night audit.
 */
class Properties
{
    /** @var array<string, ?Branch> */
    private array $branches = [];

    public function branch(string $branchId): ?Branch
    {
        return $this->branches[$branchId] ??= Branch::query()->find($branchId);
    }

    /** The branch or a 422 `branch_unknown` / `branch_archived`. */
    public function require(string $branchId, string $field = 'branch_id'): Branch
    {
        $branch = $this->branch($branchId) ?? throw HotelError::make(422, 'branch_unknown', field: $field);

        if ($branch->archived_at !== null) {
            throw HotelError::make(422, 'branch_archived', field: $field);
        }

        return $branch;
    }

    /** The property row of a branch, made on first use. */
    public function ensure(string $branchId): Property
    {
        $branch = $this->branch($branchId) ?? throw HotelError::make(422, 'branch_unknown', field: 'branch_id');

        $property = Property::query()->where('branch_id', $branchId)->first();

        if ($property !== null) {
            return $property;
        }

        Property::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => app(TenantContext::class)->require(),
            'company_id' => $branch->company_id,
            'branch_id' => $branchId,
            'business_date' => CompanyClock::today($branch->company_id, $branchId),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Property::query()->where('branch_id', $branchId)->firstOrFail();
    }

    /** The property row locked for the rest of the transaction (night audit, business-date reads that must not move). */
    public function lock(string $branchId): Property
    {
        $this->ensure($branchId);

        return Property::query()->where('branch_id', $branchId)->lockForUpdate()->firstOrFail();
    }

    /** The branch's business date (Y-m-d). */
    public function businessDate(string $branchId): string
    {
        return $this->ensure($branchId)->business_date->toDateString();
    }

    public function businessDay(string $branchId): CarbonImmutable
    {
        return CarbonImmutable::parse($this->businessDate($branchId));
    }
}
