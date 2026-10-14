<?php

namespace Modules\HR\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\DeviceSecrets;
use Modules\HR\Attendance\ClockPins;
use Modules\HR\HrServiceProvider;
use Modules\HR\Models\ClockPin;
use Modules\HR\Models\Employee;

/**
 * HR-23, NFR-04: who clocks in on this till and the material to check
 * their clock-in PIN offline (ClockPins::material under the device's
 * current secret, never the PIN): active employees with the `till`
 * clock-in method and a PIN, placed at the device's location or at its
 * branch without a location. Name and number only; nothing else about
 * them. A snapshot: a new PIN, a move or a termination replaces the set.
 */
class ClockStaffSource implements SnapshotSource
{
    public function __construct(
        private readonly ClockPins $pins,
        private readonly DeviceSecrets $secrets,
    ) {}

    public function key(): string
    {
        return 'hr_clock_staff';
    }

    public function module(): string
    {
        return HrServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $secret = $this->secrets->current($scope->device);

        if ($secret === null) {
            return [];
        }

        $employees = self::atDevice($scope)->orderBy('first_name')->orderBy('last_name')->orderBy('id')->get(['id', 'number', 'first_name', 'last_name']);
        $pins = ClockPin::query()->whereIn('employee_id', $employees->pluck('id')->all())->get()->keyBy('employee_id');
        $rows = [];

        foreach ($employees as $employee) {
            $pin = $pins->get($employee->id);

            if ($pin === null) {
                continue;
            }

            $rows[] = [
                'id' => $employee->id,
                'number' => $employee->number,
                'name' => $employee->displayName(),
                'version' => $pin->version,
                'pin' => $this->pins->material($pin, $secret),
            ];
        }

        return $rows;
    }

    /** Active employees clocking in on the till at the device's place. */
    public static function atDevice(DeviceScope $scope)
    {
        return Employee::query()->where('status', Employee::ACTIVE)
            ->whereRaw("clock_methods @> '[\"till\"]'::jsonb")
            ->where(fn ($q) => $q->where('location_id', $scope->location->id)
                ->orWhere(fn ($w) => $w->whereNull('location_id')->where('branch_id', $scope->branch->id)));
    }
}
