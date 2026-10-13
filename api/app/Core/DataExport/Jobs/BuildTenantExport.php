<?php

namespace App\Core\DataExport\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\DataExport\Models\TenantExport;
use App\Core\DataExport\TenantExports;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * EXP-02: builds one full tenant export in its tenant's context
 * (TenantAware) on the runtime connection, under row-level security. Never
 * retried: a failed build is marked failed and the Owner asks again. Runs
 * on the `exports` queue (Horizon's supervisor-exports, whose connection
 * reserves jobs for longer than the timeout).
 */
class BuildTenantExport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public string $tenantId,
        public string $exportId,
    ) {
        $this->timeout = (int) config('data_export.timeout', 3600);
        $this->onQueue(config('data_export.queue', 'exports'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(TenantExports $exports, AuditContext $audit): void
    {
        // The system builds the export: no user or device is recorded (AUD-02).
        $audit->reset();
        $export = TenantExport::query()->find($this->exportId);

        if ($export !== null) {
            $exports->build($export);
        }
    }

    /** A worker killed by the timeout: the export is failed, not left running. */
    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->run($this->tenantId, function () {
            $export = TenantExport::query()->find($this->exportId);

            if ($export !== null && in_array($export->status, TenantExport::ACTIVE, true)) {
                app(TenantExports::class)->fail($export, 'build_failed');
            }
        });
    }
}
