<?php

namespace Modules\CRM\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\CRM\Activities\Reminders;
use Modules\CRM\CrmServiceProvider;

/** CRM-27: send the activity reminders that are due, in each tenant with one due and the module active. */
class RemindCommand extends Command
{
    protected $signature = 'crm:remind';

    protected $description = 'Send CRM activity reminders that are due';

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, Reminders $reminders): int
    {
        $at = CarbonImmutable::now();

        // ADR 002: only the tenants with a reminder due (a narrow security-definer function).
        foreach ($tenants->withDueCrmReminders($at) as $tenantId) {
            $context->run($tenantId, function () use ($modules, $reminders, $at, $tenantId) {
                if ($modules->isActive(CrmServiceProvider::MODULE)) {
                    $sent = $reminders->run($at);

                    if ($sent > 0) {
                        $this->line("{$tenantId}: {$sent} reminders sent");
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
