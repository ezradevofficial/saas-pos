<?php

namespace Modules\HR\Listeners;

use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\HR\Attendance\Timesheets;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;

/**
 * HR-25: approved or cancelled leave changes the timesheet days it covers
 * (a planned shift on a leave day is leave, not an absence). Each date is
 * recomputed unless approved or in a closed period (Timesheets::touch).
 */
class RefreshLeaveDays implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Timesheets $timesheets,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(LeaveApproved|LeaveCancelled $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            foreach ($event->dates as $day) {
                $this->timesheets->touch($event->employeeId, $day['date']);
            }
        });
    }
}
