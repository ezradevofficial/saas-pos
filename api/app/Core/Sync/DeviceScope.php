<?php

namespace App\Core\Sync;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Where a paired device sits (TEN-05): its location, branch, company and
 * tenant. Sync sources answer for this place only. `at` is the request's
 * instant, so every entity of one pull is computed for the same moment.
 */
final class DeviceScope
{
    public function __construct(
        public readonly Device $device,
        public readonly Location $location,
        public readonly Branch $branch,
        public readonly Company $company,
        public readonly string $tenantId,
        public readonly CarbonImmutable $at,
    ) {}

    public static function of(Device $device): self
    {
        $location = $device->location()->with('branch.company')->firstOrFail();

        return new self(
            $device,
            $location,
            $location->branch,
            $location->branch->company,
            app(TenantContext::class)->require(),
            CarbonImmutable::now()->utc(),
        );
    }

    public function companyId(): string
    {
        return $this->company->id;
    }

    /** The branch's time zone, else the company's (receipts, tax dates). */
    public function timezone(): string
    {
        return $this->branch->timezone ?: ($this->company->timezone ?: 'UTC');
    }
}
