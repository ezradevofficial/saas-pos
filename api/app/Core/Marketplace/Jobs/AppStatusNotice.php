<?php

namespace App\Core\Marketplace\Jobs;

use App\Core\Audit\Auditor;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * ADM-05: staff suspended (or reinstated) an app; the tenant that
 * installed it gets the decision in its own audit chain, in its context
 * (TenantAware). The install itself is unchanged: a suspended app's
 * tokens and webhooks stop because the app no longer serves.
 */
class AppStatusNotice implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(
        public string $tenantId,
        public string $appId,
        public bool $suspended,
        public string $reason,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(Auditor $auditor): void
    {
        $installation = AppInstallation::query()->where('app_id', $this->appId)->where('status', AppInstallation::ACTIVE)->first();

        if ($installation === null) {
            return;
        }

        $auditor->record($this->suspended ? 'core.app.suspended' : 'core.app.reinstated', $installation, null, ['app_id' => $this->appId, 'reason' => $this->reason]);
    }
}
