<?php

namespace App\Core\Onboarding\Console;

use App\Core\Onboarding\Assisted\AssistedOnboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ONB-08: assisted tenants still not handed over after
 * onboarding.handover_report_days (30), listed for staff (the log and the
 * console's GET platform/tenants/awaiting-handover). Nothing is changed or
 * deleted: staff follow up, resend the invitation or close the tenant.
 */
class HandoverReportCommand extends Command
{
    protected $signature = 'onboarding:handover-report';

    protected $description = 'List assisted tenants not handed over in time (ONB-08)';

    public function handle(AssistedOnboarding $assisted): int
    {
        $rows = $assisted->awaitingHandover((int) config('onboarding.handover_report_days', 30));

        if ($rows !== []) {
            Log::warning('Assisted tenants not handed over', ['tenants' => $rows]);
            $this->table(['Tenant', 'Business', 'Staff', 'Contact', 'Created'], array_map('array_values', $rows));
        }

        $this->components->info('Assisted tenants waiting for handover: '.count($rows).'.');

        return self::SUCCESS;
    }
}
