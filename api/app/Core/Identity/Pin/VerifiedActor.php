<?php

namespace App\Core\Identity\Pin;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * AUTH-07: who a device's sign-in attestation proves was signed in at the
 * till: the user, the session the device made for that sign-in, when
 * (UTC), and whether the server checked that sign-in itself (`online`:
 * POST pos/pin/verify with the session id) or only the device did (the
 * device's claim: whoever holds its secret could have signed it).
 */
final class VerifiedActor
{
    public function __construct(
        public readonly User $user,
        public readonly string $sessionId,
        public readonly CarbonImmutable $signedInAt,
        public readonly bool $online,
    ) {}
}
