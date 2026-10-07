<?php

namespace App\Core\Tenancy\Jobs;

use App\Core\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware: runs the job inside its tenant's context. Jobs carry a
 * public string $tenantId (TEN-01).
 */
class TenantAware
{
    public function handle(object $job, Closure $next): mixed
    {
        return app(TenantContext::class)->run($job->tenantId, fn () => $next($job));
    }
}
