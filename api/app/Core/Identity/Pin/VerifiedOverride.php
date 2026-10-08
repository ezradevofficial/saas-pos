<?php

namespace App\Core\Identity\Pin;

use Carbon\CarbonImmutable;

/**
 * AUTH-08: a manager override that verified and is now used for
 * `reference`. Both users are recorded: the manager who authorised it and
 * the cashier it was authorised for (null when the device did not say).
 *
 * `managerHoldsPermission` is checked at redemption: an offline override
 * stays valid evidence of what happened at the till (the device wins for
 * completed sales), but a manager who has since lost the permission is
 * worth flagging for review. `firstUse` is false when the same override
 * was already redeemed for the same record (an idempotent re-upload).
 */
final class VerifiedOverride
{
    public function __construct(
        public readonly string $overrideId,
        public readonly string $mode,
        public readonly string $managerUserId,
        public readonly ?string $cashierUserId,
        public readonly string $permission,
        public readonly ?string $reference,
        public readonly CarbonImmutable $authorisedAt,
        public readonly bool $managerHoldsPermission,
        public readonly bool $firstUse,
    ) {}
}
