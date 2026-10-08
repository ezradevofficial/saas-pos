<?php

namespace App\Core\Sync\Sources;

use App\Core\Identity\Pin\DevicePinState;
use App\Core\Identity\Pin\Pins;
use App\Core\Identity\Pin\UserPin;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\DeviceSecrets;
use App\Core\Sync\StaffDirectory;

/**
 * AUTH-06..AUTH-08, RBAC-04..RBAC-06, NFR-04: who may sign in at this till
 * and what they may do there (StaffDirectory): name, till permissions,
 * limit rules, field rules for items and parties, and the material to
 * check their PIN and staff card offline (Pins::material under the
 * device's current secret, never the PIN), with the user's lock state on
 * this device. Nothing else about users (no email, phone or other roles).
 *
 * `offline` false (Owners by template, see StaffDirectory): no PIN
 * material; the till signs them in online only. `must_change`: the till
 * asks for a new PIN (POST pos/pin/change) before anything else.
 *
 * A snapshot: anyone who loses their role, is deactivated or gets a new
 * PIN changes the set, and the device replaces its staff table.
 */
class StaffSource implements SnapshotSource
{
    public function __construct(
        private readonly StaffDirectory $directory,
        private readonly Pins $pins,
        private readonly DeviceSecrets $secrets,
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
        $secret = $this->secrets->current($scope->device);
        $approvers = (array) config('sync.override_permissions', []);

        $rows = [];

        foreach ($staff as $id => $member) {
            $pin = $pins->get($id);
            $state = $states->get($id);
            // PIN material only for staff the till may check offline, under the current secret.
            $material = $pin !== null && $secret !== null && $member['offline'];
            // AUTH-08: approvers with a short PIN (set before they could approve) choose a new one.
            $shortForApprover = $pin?->hasPin() && (int) $pin->pin_digits < 6 && array_intersect($approvers, $member['permissions']) !== [];

            $rows[] = [
                'id' => $id,
                'name' => $member['name'],
                'permissions' => $member['permissions'],
                'limits' => (object) ($limits[$id] ?? []),
                'field_rules' => $fieldRules[$id] ?? [],
                'offline' => $member['offline'],
                'pin' => $material ? $this->pins->material($pin, $secret, Pins::PIN) : null,
                'card' => $material ? $this->pins->material($pin, $secret, Pins::CARD) : null,
                'pin_version' => $pin?->version ?? 0,
                'must_change' => (bool) $pin?->hasPin() && ($pin->must_change || $shortForApprover),
                'failed_attempts' => $state?->failed_attempts ?? 0,
                'locked' => (bool) $state?->isLocked(),
            ];
        }

        return $rows;
    }
}
