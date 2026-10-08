<?php

namespace Modules\POS\Sync;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;

/**
 * AUTH-08: checks the proof a till sends that a manager authorised a
 * restricted action (void, refund, price override, discount above limit,
 * cash movement) by entering their PIN on the device. Phase 4 Task 2
 * provides the PIN-backed implementation and binds it in place of
 * UnverifiedOverrides.
 */
interface OverrideVerifier
{
    /**
     * @param  string  $action  the permission authorised, e.g. `pos.sale.refund`
     * @param  string  $subjectId  the record it was authorised for (sale, refund, line...)
     */
    public function verify(User $manager, ?string $proof, string $action, string $subjectId, Device $device): bool;
}
