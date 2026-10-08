<?php

namespace Modules\POS\Sync;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;

/**
 * Until AUTH-08's PIN proof exists (phase 4 Task 2), no override proof can
 * be verified: the manager's permission and limits are still checked, the
 * record is kept with `override_verified = false` and the sale or refund
 * is flagged `override_unverified` for review.
 */
class UnverifiedOverrides implements OverrideVerifier
{
    public function verify(User $manager, ?string $proof, string $action, string $subjectId, Device $device): bool
    {
        return false;
    }
}
