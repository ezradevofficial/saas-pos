<?php

namespace App\Core\Sso\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Sso\Domains\SsoDomains;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * AUTH-11: check, re-check and expire one tenant's SSO email domains in
 * its context (TenantAware). Dispatched by `domains:verify`; unique per
 * tenant while queued or running.
 */
class VerifyTenantSsoDomains implements ShouldBeUnique, ShouldQueue
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

    public function handle(SsoDomains $domains, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $domains->runDue(CarbonImmutable::parse($this->at));
    }
}
