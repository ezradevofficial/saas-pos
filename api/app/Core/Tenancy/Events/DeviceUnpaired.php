<?php

namespace App\Core\Tenancy\Events;

use App\Core\Tenancy\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A device was unpaired (TEN-05): its tokens are revoked. Dispatched inside
 * the unpairing transaction and the tenant's context, so listeners (POS
 * retires the device's number ranges, NUM-02) commit or roll back with it.
 */
class DeviceUnpaired
{
    use Dispatchable;

    public function __construct(public readonly Device $device) {}
}
