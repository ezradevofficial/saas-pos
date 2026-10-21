<?php

namespace App\Core\Sso\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Sso\Saml\SamlMetadataRefresh;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * AUTH-11: re-read one tenant's SAML metadata URLs in its context
 * (TenantAware). Dispatched by `sso:refresh-metadata`; unique per tenant.
 */
class RefreshTenantSamlMetadata implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(SamlMetadataRefresh $refresh, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $refresh->runDue();
    }
}
