<?php

namespace App\Core\Identity\Pin;

use Carbon\CarbonImmutable;

/**
 * AUTH-08: a manager override that verified and is now used for
 * `reference`. Both users are recorded: the manager who authorised it and
 * the cashier it was authorised for (null when the device did not say).
 *
 * Checked at redemption: whether the manager is still active, still works
 * at the device's location (holds the till sign-in permission there) and
 * still holds the permission there. An offline override stays valid
 * evidence of what happened at the till, but the POS module flags it for
 * review when any of these is false (and shows every offline override).
 */
final class VerifiedOverride
{
    public function __construct(
        public readonly string $overrideId,
        public readonly string $mode,
        public readonly string $managerUserId,
        public readonly ?string $cashierUserId,
        public readonly string $permission,
        public readonly string $reference,
        public readonly CarbonImmutable $authorisedAt,
        public readonly bool $managerActive,
        public readonly bool $managerStaffAtLocation,
        public readonly bool $managerHoldsPermission,
    ) {}

    /** Offline, or a manager who no longer qualifies: to be reviewed. */
    public function needsReview(): bool
    {
        return $this->mode === OverrideRedemption::OFFLINE || ! $this->managerActive || ! $this->managerStaffAtLocation || ! $this->managerHoldsPermission;
    }
}
