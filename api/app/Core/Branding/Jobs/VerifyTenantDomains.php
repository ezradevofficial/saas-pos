<?php

namespace App\Core\Branding\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Branding\Domains\TenantDomains;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * BR-05: check, re-check and expire the custom domains of one tenant, in that tenant's
 * context (TenantAware), on the runtime connection. Dispatched by
 * `domains:verify`; unique per tenant while queued or running.
 */
class VerifyTenantDomains implements ShouldBeUnique, ShouldQueue
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

    public function handle(TenantDomains $domains, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $domains->runDue(CarbonImmutable::parse($this->at));
    }
}
