<?php

namespace Modules\POS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** POS-13: a shift was opened on a device (after commit, once). */
class ShiftOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $shiftId,
    ) {}
}
