<?php

namespace App\Core\DataExport\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\DataExport\TenantExports;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * EXP-02: deletes one tenant's expired export files and fails its stale
 * builds, in that tenant's context (TenantAware) on the runtime
 * connection. Dispatched by `exports:expire`; unique per tenant.
 */
class ExpireTenantExports implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 900;

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

    public function handle(TenantExports $exports, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $exports->expireDue(CarbonImmutable::parse($this->at));
    }
}
