<?php

namespace Modules\Payroll\Listeners;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\HR\Events\AttendancePeriodClosed;
use Modules\HR\Events\ContractChanged;
use Modules\HR\Events\EmployeeHired;
use Modules\HR\Events\EmployeeTerminated;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;
use Modules\Payroll\Employees\EmployeeCopy;
use Modules\Payroll\PayrollServiceProvider;

/**
 * PAY-05, PAY-11, PAY-12, RBAC-08: HR's events (HR-33) reach Payroll's
 * copy here, queued after HR's transaction commits and run in the event's
 * tenant. Nothing is queued for a tenant without Payroll; HR may be off
 * (Payroll alone brings HR's employee core, owner decision B).
 */
class FollowHr implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly EmployeeCopy $copy,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(object $event): bool
    {
        $check = fn () => $this->modules->isActive(PayrollServiceProvider::MODULE);

        return $this->tenants->id() === $event->tenantId ? $check() : $this->tenants->run($event->tenantId, $check);
    }

    public function handle(EmployeeHired|EmployeeTerminated|ContractChanged|LeaveApproved|LeaveCancelled|AttendancePeriodClosed $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(PayrollServiceProvider::MODULE)) {
                return;
            }

            match (true) {
                $event instanceof EmployeeHired => $this->copy->hired($event),
                $event instanceof EmployeeTerminated => $this->copy->terminated($event),
                $event instanceof ContractChanged => $this->copy->contract($event),
                $event instanceof LeaveApproved => $this->copy->leaveApproved($event),
                $event instanceof LeaveCancelled => $this->copy->leaveCancelled($event),
                $event instanceof AttendancePeriodClosed => $this->copy->attendanceClosed($event),
            };
        });
    }
}
