<?php

namespace App\Core\Platform\Console;

use App\Core\Platform\PlatformAuditor;
use Illuminate\Console\Command;

/** AUD-03 for platform staff: check the platform audit chain. */
class VerifyPlatformAudit extends Command
{
    protected $signature = 'platform:audit-verify';

    protected $description = 'Verify the platform staff audit hash chain';

    public function handle(PlatformAuditor $auditor): int
    {
        $broken = $auditor->verify();

        if ($broken !== null) {
            $this->error("The platform audit chain is broken at entry {$broken}.");

            return self::FAILURE;
        }

        $this->info('The platform audit chain is intact.');

        return self::SUCCESS;
    }
}
