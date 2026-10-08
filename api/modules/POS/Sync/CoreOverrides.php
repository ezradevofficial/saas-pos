<?php

namespace Modules\POS\Sync;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;

/**
 * AUTH-08 through core's verifier (App\Core\Identity\Pin\OverrideVerifier,
 * branch feat/core-sync). Bound by PosServiceProvider only when that class
 * exists. Core's refusals (`override_invalid`, `override_expired`,
 * `override_mismatch`, `override_replayed`) become the record's rejection.
 *
 * AUTH-07 sign-in attestations are not in core yet: actor() proves nothing
 * until they are (TODO: verify `actor_proof` through core when it ships).
 */
class CoreOverrides implements OverrideVerifier
{
    public function redeem(Device $device, array $override, string $permission, string $reference): ?OverrideProof
    {
        try {
            /** @var object{managerUserId: string, cashierUserId: ?string, managerHoldsPermission: bool, firstUse: bool} $verified */
            $verified = app('App\Core\Identity\Pin\OverrideVerifier')->redeem($device, $override, $permission, $reference);
        } catch (ApiException $e) {
            throw new Rejection(in_array($e->errorCode, ['override_invalid', 'override_expired', 'override_mismatch', 'override_replayed'], true) ? $e->errorCode : 'override_invalid', 'override');
        }

        return new OverrideProof($verified->managerUserId, $verified->cashierUserId, $verified->managerHoldsPermission, $verified->firstUse);
    }

    public function actor(Device $device, User $user, ?string $proof, string $reference): bool
    {
        return false;
    }
}
