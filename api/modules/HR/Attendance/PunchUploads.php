<?php

namespace Modules\HR\Attendance;

use App\Core\Http\ApiException;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Modules\HR\Models\AttendanceDevice;
use Modules\HR\Models\Employee;
use Modules\HR\Models\EmployeePlace;
use Modules\HR\Models\Punch;
use Modules\HR\Rosters\Schedules;
use Modules\HR\Sync\ClockStaffSource;

/**
 * HR-23, HR-24, NFR-04: punches uploaded by tills (clock-in with a PIN,
 * verified on the till) and by biometric devices. One result per punch,
 * the same however often it is sent: `stored` (with the punch's flags) or
 * `rejected` with a code.
 */
class PunchUploads
{
    /** NFR-04: how long a till may queue punches offline. */
    public const OFFLINE_DAYS = 7;

    public function __construct(
        private readonly Punches $punches,
        private readonly Schedules $schedules,
    ) {}

    /**
     * @param  list<array{id: string, employee_id: string, kind: string, occurred_at: string}>  $uploads
     * @return list<array<string, mixed>>
     */
    public function fromTill(Device $device, array $uploads): array
    {
        $scope = DeviceScope::of($device);
        $allowed = ClockStaffSource::atDevice($scope)->pluck('id')->flip()->all();
        $results = [];

        foreach ($uploads as $upload) {
            $existing = Punch::query()->whereKey($upload['id'])->first();

            if ($existing !== null) {
                $results[] = self::stored($existing);

                continue;
            }

            $at = CarbonImmutable::parse($upload['occurred_at'])->utc();

            if (! self::inWindow($at)) {
                $results[] = self::rejected($upload['id'], 'punch_out_of_window');

                continue;
            }

            $employee = Employee::query()->find($upload['employee_id']);

            // Offline punches of someone who has since moved or left still count where they worked that day.
            if ($employee === null || (! isset($allowed[$employee->id]) && ! $this->wasHere($employee, $scope, $at))) {
                $results[] = self::rejected($upload['id'], 'not_at_device');

                continue;
            }

            $results[] = $this->store($employee, [
                'id' => $upload['id'], 'kind' => $upload['kind'], 'occurred_at' => $at,
                'source' => 'till', 'device_id' => $device->id, 'location_id' => $device->location_id,
            ], $upload['id']);
        }

        return $results;
    }

    /**
     * @param  list<array{external_id: string, ref: string, kind: string, occurred_at: string}>  $uploads
     * @return list<array<string, mixed>>
     */
    public function fromDevice(AttendanceDevice $device, array $uploads): array
    {
        $results = [];

        foreach ($uploads as $upload) {
            $existing = Punch::query()->where('attendance_device_id', $device->id)->where('external_id', $upload['external_id'])->first();

            if ($existing !== null) {
                $results[] = [...self::stored($existing), 'external_id' => $upload['external_id']];

                continue;
            }

            $at = CarbonImmutable::parse($upload['occurred_at'])->utc();

            if (! self::inWindow($at)) {
                $results[] = [...self::rejected(null, 'punch_out_of_window'), 'external_id' => $upload['external_id']];

                continue;
            }

            // Only active employees are matched by their enrolment reference.
            $employee = Employee::query()->where('company_id', $device->company_id)->where('status', Employee::ACTIVE)->where('biometric_ref', $upload['ref'])->first();

            if ($employee === null) {
                $results[] = [...self::rejected(null, 'unknown_ref'), 'external_id' => $upload['external_id']];

                continue;
            }

            $kind = $upload['kind'] === 'auto' ? $this->punches->nextKind($employee, $at, $this->schedules->timezoneOf($employee)) : $upload['kind'];

            $results[] = [...$this->store($employee, [
                'kind' => $kind, 'occurred_at' => $at, 'source' => 'device', 'attendance_device_id' => $device->id,
                'external_id' => $upload['external_id'], 'location_id' => $device->location_id,
            ], null), 'external_id' => $upload['external_id']];
        }

        $device->forceFill(['last_seen_at' => CarbonImmutable::now()])->saveQuietly();

        return $results;
    }

    /** @param array<string, mixed> $data */
    private function store(Employee $employee, array $data, ?string $id): array
    {
        try {
            [$punch] = $this->punches->record($employee, $data);

            return self::stored($punch);
        } catch (ApiException $e) {
            return self::rejected($id, $e->errorCode);
        }
    }

    /**
     * HR-23: the device's place was the employee's on the punch's date
     * (their place history), so a till's queued offline punches survive a
     * move or a termination.
     */
    private function wasHere(Employee $employee, DeviceScope $scope, CarbonImmutable $at): bool
    {
        $date = $at->setTimezone($this->schedules->timezoneOf($employee))->toDateString();

        return $employee->company_id === $scope->company->id && EmployeePlace::query()->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->where(fn ($q) => $q->where('location_id', $scope->location->id)->orWhere(fn ($w) => $w->whereNull('location_id')->where('branch_id', $scope->branch->id)))
            ->exists();
    }

    /** HR-22, NFR-04: uploaded punches are at most 7 days old (the till's offline window) and never more than an hour ahead. */
    public static function inWindow(CarbonImmutable $at): bool
    {
        $now = CarbonImmutable::now();

        return $at->greaterThanOrEqualTo($now->subDays(self::OFFLINE_DAYS)) && $at->lessThanOrEqualTo($now->addHour());
    }

    /** @return array<string, mixed> */
    public static function stored(Punch $punch): array
    {
        return ['id' => $punch->id, 'status' => 'stored', 'employee_id' => $punch->employee_id, 'kind' => $punch->kind, 'flags' => $punch->flags ?? [], 'received_at' => $punch->received_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public static function rejected(?string $id, string $code): array
    {
        return ['id' => $id, 'status' => 'rejected', 'code' => $code, 'message' => __('hr.errors.'.$code)];
    }
}
