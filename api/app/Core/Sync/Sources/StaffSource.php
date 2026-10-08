<?php

namespace App\Core\Sync\Sources;

use App\Core\Identity\Pin\DevicePinState;
use App\Core\Identity\Pin\Pins;
use App\Core\Identity\Pin\UserPin;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\StaffDirectory;

/**
 * AUTH-06..AUTH-08, RBAC-04..RBAC-06, NFR-04: who may sign in at this till
 * and what they may do there (StaffDirectory): name, till permissions,
 * limit rules, field rules for items and parties, and the material to
 * check their PIN and staff card offline (Pins::material, never the PIN),
 * with the user's lock state on this device. Nothing else about users
 * (no email, phone or other roles).
 *
 * A snapshot: anyone who loses their role, is deactivated or gets a new
 * PIN changes the set, and the device replaces its staff table.
 */
class StaffSource implements SnapshotSource
{
    public function __construct(
        private readonly StaffDirectory $directory,
        private readonly Pins $pins,
    ) {}

    public function key(): string
    {
        return 'staff';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $staff = $this->directory->at($scope);

        if ($staff === []) {
            return [];
        }

        $ids = array_keys($staff);
        $limits = $this->directory->limits($staff);
        $fieldRules = $this->directory->fieldRules($ids);
        $pins = UserPin::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $states = DevicePinState::query()->where('device_id', $scope->device->id)->whereIn('user_id', $ids)->get()->keyBy('user_id');

        $rows = [];

        foreach ($staff as $id => $member) {
            $pin = $pins->get($id);
            $state = $states->get($id);

            $rows[] = [
                'id' => $id,
                'name' => $member['name'],
                'permissions' => $member['permissions'],
                'limits' => (object) ($limits[$id] ?? []),
                'field_rules' => $fieldRules[$id] ?? [],
                'pin' => $pin === null ? null : $this->pins->material($pin, $scope->device, Pins::PIN),
                'card' => $pin === null ? null : $this->pins->material($pin, $scope->device, Pins::CARD),
                'pin_version' => $pin?->version ?? 0,
                'failed_attempts' => $state?->failed_attempts ?? 0,
                'locked' => (bool) $state?->isLocked(),
            ];
        }

        return $rows;
    }
}
