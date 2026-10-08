<?php

namespace Modules\POS\Sync;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;

/**
 * Until core's PIN verifier is on main, nothing can be proven: restricted
 * money-out actions are held for review and sales are flagged
 * `actor_unverified` / `override_unverified`.
 */
class UnverifiedOverrides implements OverrideVerifier
{
    public function redeem(Device $device, array $override, string $permission, string $reference): ?OverrideProof
    {
        return null;
    }

    public function actor(Device $device, User $user, ?string $proof, string $reference): bool
    {
        return false;
    }
}
