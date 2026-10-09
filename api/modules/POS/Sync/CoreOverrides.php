<?php

namespace Modules\POS\Sync;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\OverrideAlreadyApplied;
use App\Core\Identity\Pin\OverrideRedemption;
use App\Core\Identity\Pin\OverrideVerifier as CoreVerifier;
use App\Core\Identity\Pin\VerifiedOverride;
use App\Core\Tenancy\Models\Device;

/**
 * AUTH-08 through core's verifier (App\Core\Identity\Pin\OverrideVerifier):
 * an online `{token}` or the offline signed form (message v2 with the
 * device secret's `kid`), redeemed once for the record's id.
 *
 * - Already applied to this very record (a resend): the earlier result
 *   stands (OverrideAlreadyApplied carries it).
 * - Core's refusals (`override_invalid`, `override_expired`,
 *   `override_mismatch`, `override_replayed`, `override_reference_required`)
 *   become the record's rejection.
 * - A manager who no longer qualifies (inactive, not staff at the location,
 *   without the permission) leaves the action unproven; an offline
 *   override is applied and flagged `override_offline` for review.
 *
 * AUTH-07 sign-in attestations are not in core yet: actor() proves nothing
 * until they are (TODO: verify `actor_proof` through core when it ships).
 */
class CoreOverrides implements OverrideVerifier
{
    public function __construct(private readonly CoreVerifier $verifier) {}

    public function redeem(Device $device, array $override, string $permission, string $reference): ?OverrideProof
    {
        try {
            return $this->proof($this->verifier->redeem($device, $override, $permission, $reference));
        } catch (OverrideAlreadyApplied $e) {
            return $this->proof($e->override);
        } catch (ApiException $e) {
            $known = ['override_invalid', 'override_expired', 'override_mismatch', 'override_replayed', 'override_reference_required'];

            throw new Rejection(in_array($e->errorCode, $known, true) ? $e->errorCode : 'override_invalid', 'override');
        }
    }

    public function actor(Device $device, User $user, ?string $proof, string $reference): bool
    {
        return false;
    }

    private function proof(VerifiedOverride $verified): OverrideProof
    {
        return new OverrideProof(
            $verified->managerUserId,
            $verified->cashierUserId,
            $verified->managerActive && $verified->managerStaffAtLocation && $verified->managerHoldsPermission,
            $verified->mode === OverrideRedemption::OFFLINE,
        );
    }
}
