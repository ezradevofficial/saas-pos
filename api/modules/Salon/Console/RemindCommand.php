<?php

namespace Modules\Salon\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Salon\Appointments\Lifecycle;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Reminder;
use Modules\Salon\Packages\Packages;
use Modules\Salon\Payments\Deposits;
use Modules\Salon\Reminders\Notices;
use Modules\Salon\SalonServiceProvider;
use Modules\Salon\Support\Settings;

/**
 * SAL-14, SAL-11, SAL-18: the salon's periodic work, every ten minutes. For each tenant with the module:
 * - a reminder to each booked appointment starting within the company's `reminder_hours` (once per appointment and start
 *   time; transactional, so marketing consent does not apply);
 * - an online booking held for a deposit that was not paid in time is released (cancelled, no fee, its time freed);
 * - packages past their last day are marked expired.
 */
class RemindCommand extends Command
{
    protected $signature = 'salon:remind';

    protected $description = 'Send appointment reminders, release unpaid online holds and expire packages';

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, Notices $notices, Lifecycle $lifecycle, Deposits $deposits, Packages $packages): int
    {
        $sent = 0;
        $released = 0;

        foreach ($tenants->active() as $tenantId) {
            $context->run($tenantId, function () use ($modules, $notices, $lifecycle, $deposits, $packages, &$sent, &$released) {
                if (! $modules->isActive(SalonServiceProvider::MODULE)) {
                    return;
                }

                $now = CarbonImmutable::now();

                foreach (Appointment::query()->where('status', Appointment::BOOKED)->whereNotNull('deposit_hold_until')->where('deposit_hold_until', '<=', $now)->orderBy('deposit_hold_until')->limit(200)->get() as $held) {
                    if ($deposits->balance($held) < $held->deposit_required_minor) {
                        rescue(function () use ($lifecycle, $held, &$released) {
                            $lifecycle->cancel($held, __('salon.deposit_timeout', [], $held->locale), null, null, withFee: false);
                            $released++;
                        }, report: true);
                    }
                }

                foreach (Appointment::query()->where('status', Appointment::BOOKED)->where('starts_at', '>', $now)->where('starts_at', '<=', $now->addHours(168))->orderBy('starts_at')->limit(500)->get() as $appointment) {
                    $hours = Settings::for($appointment->company_id)['reminder_hours'];

                    if ($appointment->starts_at->greaterThan($now->addHours($hours)) || $appointment->deposit_hold_until !== null) {
                        continue;
                    }

                    $version = substr(hash('sha256', $appointment->starts_at->toIso8601String()), 0, 12);

                    if (! Reminder::query()->where('appointment_id', $appointment->id)->where('kind', 'reminder')->where('dedupe', $version)->exists() && $notices->send(Notices::REMINDER, $appointment)) {
                        $sent++;
                    }
                }

                $packages->expireDue();
            });
        }

        $this->info("Sent {$sent} reminders, released {$released} unpaid holds.");

        return self::SUCCESS;
    }
}
