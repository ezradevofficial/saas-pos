<?php

namespace App\Core\Limits\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Limits\UsageLimits;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * LIM-01..LIM-03: meters one tenant and follows its overages, in that
 * tenant's context (TenantAware) on the runtime connection. Dispatched by
 * `usage:meter`; unique per tenant.
 */
class MeterTenantUsage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $tenantId,
        public string $at,
    ) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(UsageLimits $limits, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $limits->meter(CarbonImmutable::parse($this->at));
    }
}
