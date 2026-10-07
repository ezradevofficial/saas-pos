<?php

namespace App\Core\Tenancy\Policies;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;

/**
 * TEN-05, RBAC-04: devices are checked at their location. Issuing a pairing
 * code and unpairing need `core.device.pair`; suspending (retiring) a device
 * and resuming it need `core.device.archive`, since devices are retired by
 * status.
 */
class DevicePolicy
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function view(User $user, Device $device): bool
    {
        return $this->resolver->can($user, 'core.device.view', $device->scope());
    }

    public function create(User $user, Location $location): bool
    {
        return $this->resolver->can($user, 'core.device.create', $location->scope());
    }

    public function update(User $user, Device $device): bool
    {
        return $this->resolver->can($user, 'core.device.edit', $device->scope());
    }

    public function pair(User $user, Device $device): bool
    {
        return $this->resolver->can($user, 'core.device.pair', $device->scope());
    }

    public function suspend(User $user, Device $device): bool
    {
        return $this->resolver->can($user, 'core.device.archive', $device->scope());
    }
}
