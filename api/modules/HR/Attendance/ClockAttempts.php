<?php

namespace Modules\HR\Attendance;

use App\Core\Approvals\Resolvers\ApproverDirectory;
use App\Core\Audit\Auditor;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\HR\Models\Employee;

/**
 * HR-23: wrong clock-in PINs a till counted (offline included) reach the
 * server: each report is audited on the employee with the device, and a
 * lockout also alerts the HR Officers at the employee's place
 * (`hr.clock_pin.locked`, in the app and by email). The lock itself stays
 * on the till until a new PIN syncs.
 */
class ClockAttempts
{
    public const LOCKED = 'hr.clock_pin.locked';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Notifier $notifier,
        private readonly ApproverDirectory $directory,
        private readonly ScopeResolver $scopes,
        private readonly TenantContext $tenants,
    ) {}

    public static function register(EventTypes $types): void
    {
        $types->register(new EventType(
            key: self::LOCKED,
            placeholders: ['employee' => 'EMP-00012 Amina Otieno', 'device' => 'Till 1', 'attempts' => '5'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            channels: [Channels::IN_APP, Channels::EMAIL],
            langKey: 'hr.notifications.clock_pin_locked',
        ));
    }

    /**
     * @param  list<array{employee_id: string, failed_attempts: int, locked: bool, at?: ?string}>  $reports
     * @return list<array{employee_id: string, recorded: bool}>
     */
    public function report(Device $device, array $reports): array
    {
        $results = [];

        foreach ($reports as $report) {
            $employee = Employee::query()->find($report['employee_id']);

            if ($employee === null || $employee->company_id !== $device->location()->first()?->branch()->value('company_id')) {
                $results[] = ['employee_id' => $report['employee_id'], 'recorded' => false];

                continue;
            }

            $locked = (bool) $report['locked'];
            $this->auditor->record($locked ? 'hr.employee.clock_pin_locked' : 'hr.employee.clock_pin_failures', $employee, null, [
                'device_id' => $device->id, 'failed_attempts' => (int) $report['failed_attempts'], 'at' => $report['at'] ?? null,
            ], ['device_time' => isset($report['at']) ? CarbonImmutable::parse($report['at']) : null]);

            if ($locked) {
                $this->alert($employee, $device, (int) $report['failed_attempts']);
            }

            $results[] = ['employee_id' => $employee->id, 'recorded' => true];
        }

        return $results;
    }

    private function alert(Employee $employee, Device $device, int $attempts): void
    {
        $chain = $this->scopes->chainOf($employee->scope()) ?? [];
        $officers = $this->directory->holdersAt($this->directory->rolesWith('hr.attendance.manage'), $chain);

        if ($officers === []) {
            return;
        }

        $this->notifier->send(new NotificationEvent(self::LOCKED, $officers, [
            'employee' => trim($employee->number.' '.$employee->displayName()),
            'device' => (string) $device->name,
            'attempts' => (string) $attempts,
        ]));
    }
}
