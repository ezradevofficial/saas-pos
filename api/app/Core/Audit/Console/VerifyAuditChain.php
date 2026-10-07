<?php

namespace App\Core\Audit\Console;

use App\Core\Audit\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Recomputes a tenant's audit hash chain (AUD-03). Runs under the tenant's RLS context. */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify {tenant : Tenant id}';

    protected $description = 'Verify the hash chain of a tenant\'s audit log';

    public function handle(Auditor $auditor): int
    {
        $tenant = (string) $this->argument('tenant');

        if (! Str::isUuid($tenant)) {
            $this->error('The tenant must be a UUID.');

            return self::INVALID;
        }

        $broken = $auditor->verify($tenant);

        if ($broken !== null) {
            $this->error("Audit chain broken at seq {$broken}.");

            return self::FAILURE;
        }

        $this->info('Audit chain intact.');

        return self::SUCCESS;
    }
}
