<?php

namespace App\Core\Marketplace\Jobs;

use App\Core\Audit\Auditor;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * MKT-04, AUD-01 (Phase 8 review L7): the platform switched a first-party
 * connector off (`marketplace:connector --disable`: its tokens stop) or
 * issued it new secrets (`--rotate`). Each tenant that installed it gets
 * the change in its own audit chain, in its context (TenantAware), as an
 * app suspension does (AppStatusNotice).
 */
class ConnectorChangeNotice implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DISABLED = 'disabled';

    public const ROTATED = 'rotated';

    public int $tries = 3;

    public function __construct(
        public string $tenantId,
        public string $appId,
        public string $connector,
        public string $change,
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

        $auditor->record("core.app.connector_{$this->change}", $installation, null, ['app_id' => $this->appId, 'connector' => $this->connector]);
    }
}
