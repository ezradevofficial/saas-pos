<?php

namespace Modules\HR\Attendance;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use Modules\HR\Models\AttendanceDevice;
use Modules\HR\Support\Errors;

/**
 * HR-24: biometric attendance devices. A device gets a bearer token once
 * (rotating revokes the old ones); suspended devices are refused until
 * resumed; retired ones for good (their tokens revoked).
 */
class AttendanceDevices
{
    /** @return array{device: AttendanceDevice, token: string} */
    public function create(array $data): array
    {
        $location = Location::query()->whereKey($data['location_id'])->whereNull('archived_at')->firstOrFail();
        $companyId = Branch::query()->whereKey($location->branch_id)->value('company_id');

        return Errors::transaction(function () use ($data, $location, $companyId) {
            $device = AttendanceDevice::create(['company_id' => $companyId, 'location_id' => $location->id, 'name' => $data['name'], 'vendor' => $data['vendor'] ?? null, 'status' => AttendanceDevice::ACTIVE]);

            return ['device' => $device->refresh(), 'token' => $device->issueToken()->plainTextToken];
        });
    }

    public function rotate(AttendanceDevice $device): string
    {
        if ($device->status === AttendanceDevice::RETIRED) {
            throw Errors::make(409, 'device_inactive');
        }

        return Errors::transaction(function () use ($device) {
            $device->tokens()->delete();
            app(Auditor::class)->record('hr.attendance_device.token_rotate', $device);

            return $device->issueToken()->plainTextToken;
        });
    }

    public function status(AttendanceDevice $device, string $status): AttendanceDevice
    {
        if ($device->status === AttendanceDevice::RETIRED) {
            throw Errors::make(409, 'device_inactive');
        }

        return Errors::transaction(function () use ($device, $status) {
            $device->fill(['status' => $status])->save();

            if ($status === AttendanceDevice::RETIRED) {
                $device->tokens()->delete();
            }

            return $device->refresh();
        });
    }
}
