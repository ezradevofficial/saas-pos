<?php

namespace Modules\Salon\Console;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Models\Setting;

/**
 * SAL-26, NFR-08: people who booked or queued without an account (no party) leave a name, a phone and maybe an email. They
 * are cleared once the visit is `guest_retention_days` old (the company's setting, 365 by default; walk-in queue entries
 * after 30 days); the appointment stays for the counts and the sale. Goes on schedule even if the tenant has since
 * switched the module off.
 */
class PurgeGuestsCommand extends Command
{
    protected $signature = 'salon:purge-guests';

    protected $description = 'Clear the contact details of guests (people with no account) after the company retention';

    public function handle(DueTenants $tenants, TenantContext $context, Auditor $auditor): int
    {
        foreach ($tenants->active() as $tenantId) {
            $context->run($tenantId, function () use ($auditor) {
                $custom = Setting::query()->pluck('guest_retention_days', 'company_id')->all();
                $companies = Appointment::query()->whereNull('client_id')->whereNotNull('guest_name')->whereNull('anonymised_at')->distinct()->pluck('company_id')->all();
                $cleared = 0;

                foreach ($companies as $companyId) {
                    $days = (int) ($custom[$companyId] ?? 365);
                    $cleared += Appointment::query()->where('company_id', $companyId)->whereNull('client_id')->whereNotNull('guest_name')->whereNull('anonymised_at')
                        ->where('starts_at', '<', now()->subDays($days))
                        ->update(['guest_name' => null, 'guest_phone' => null, 'guest_email' => null, 'notes' => null, 'service_notes' => null, 'anonymised_at' => now(), 'updated_at' => now()]);
                }

                $cleared += QueueEntry::query()->whereNull('anonymised_at')->where('joined_at', '<', now()->subDays(30))
                    ->update(['guest_name' => '-', 'guest_phone' => null, 'anonymised_at' => now(), 'updated_at' => now()]);

                if ($cleared > 0) {
                    $auditor->record('salon.guest.purge', null, null, ['cleared' => $cleared]);
                }
            });
        }

        return self::SUCCESS;
    }
}
