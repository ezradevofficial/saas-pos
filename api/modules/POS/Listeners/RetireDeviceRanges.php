<?php

namespace Modules\POS\Listeners;

use App\Core\Tenancy\Events\DeviceUnpaired;
use Modules\POS\Sync\NumberRanges;

/** NUM-02: an unpaired (lost) device's number ranges are retired in the same transaction. */
class RetireDeviceRanges
{
    public function __construct(private readonly NumberRanges $ranges) {}

    public function handle(DeviceUnpaired $event): void
    {
        $this->ranges->retire($event->device->id);
    }
}
