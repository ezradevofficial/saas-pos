<?php

namespace App\Core\Api\Console;

use App\Core\Api\Sandbox\SandboxExpiry;
use App\Core\Audit\AuditContext;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * API-05 (review): warns, then closes, inactive developer sandboxes, each
 * in its own context; sandbox ids from a security-definer function (ADR
 * 002). Runs daily.
 */
class ExpireSandboxesCommand extends Command
{
    protected $signature = 'sandbox:expire';

    protected $description = 'Warn and close inactive developer sandboxes (API-05).';

    public function handle(DueTenants $due, TenantContext $tenants, SandboxExpiry $expiry, AuditContext $audit): int
    {
        $now = CarbonImmutable::now();
        $results = ['warned' => 0, 'closed' => 0, 'active' => 0];

        foreach ($due->sandboxes() as $tenantId) {
            // The system acts: no user or device is recorded (AUD-02).
            $audit->reset();
            $results[$tenants->run($tenantId, fn () => $expiry->check($now))]++;
        }

        $this->info("Sandboxes warned: {$results['warned']}, closed: {$results['closed']}.");

        return self::SUCCESS;
    }
}
