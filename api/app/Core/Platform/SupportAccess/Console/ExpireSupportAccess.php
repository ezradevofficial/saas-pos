<?php

namespace App\Core\Platform\SupportAccess\Console;

use App\Core\Platform\SupportAccess\SupportAccess;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * RBAC-13f: approvals past their end become `expired`, their principals
 * are deactivated and their tokens deleted (the token is refused from the
 * end anyway). Tenant ids come from a security-definer function (ADR 002);
 * each tenant is handled in its own context. Every five minutes.
 */
class ExpireSupportAccess extends Command
{
    protected $signature = 'support:expire';

    protected $description = 'End support access grants past their expiry';

    public function handle(DueTenants $due, TenantContext $tenants, SupportAccess $support): int
    {
        $at = CarbonImmutable::now();
        $count = 0;

        // Review L10: one tenant's failure is reported and the others still expire.
        foreach ($due->withExpiredSupportGrants($at) as $tenantId) {
            try {
                $count += $tenants->run($tenantId, fn () => $support->expire($at));
            } catch (Throwable $e) {
                report($e);
                $this->components->error("Support access of tenant {$tenantId} could not be expired; it is retried on the next run.");
            }
        }

        $this->components->info("{$count} support access grants expired.");

        return self::SUCCESS;
    }
}
