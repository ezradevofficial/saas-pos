<?php

namespace Modules\HR\Attendance;

use App\Core\Http\ApiException;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Modules\HR\Models\AttendanceDevice;
use Modules\HR\Models\Employee;
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

            $employee = Employee::query()->find($upload['employee_id']);

            // Offline punches of someone who has since left this place still count up to the change: they were allowed then.
            if ($employee === null || (! isset($allowed[$employee->id]) && ! $this->wasHere($employee, $scope))) {
                $results[] = self::rejected($upload['id'], 'not_at_device');

                continue;
            }

            $results[] = $this->store($employee, [
                'id' => $upload['id'], 'kind' => $upload['kind'], 'occurred_at' => CarbonImmutable::parse($upload['occurred_at'])->utc(),
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

            $employee = Employee::query()->where('company_id', $device->company_id)->where('biometric_ref', $upload['ref'])->first();

            if ($employee === null) {
                $results[] = [...self::rejected(null, 'unknown_ref'), 'external_id' => $upload['external_id']];

                continue;
            }

            $at = CarbonImmutable::parse($upload['occurred_at'])->utc();
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

    private function wasHere(Employee $employee, DeviceScope $scope): bool
    {
        return $employee->company_id === $scope->company->id && ! $employee->isActive()
            && ($employee->location_id === $scope->location->id || ($employee->location_id === null && $employee->branch_id === $scope->branch->id));
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
