<?php

namespace App\Core\Identity\Pin;

/**
 * AUTH-07: the outcome of checking a sign-in attestation: the verified
 * actor, or why it could not be verified (one of ActorProofVerifier's
 * FAIL_* reasons). An unverified proof is not an error: callers treat the
 * action as unproven.
 */
final class ActorProofResult
{
    private function __construct(
        public readonly ?VerifiedActor $actor,
        public readonly ?string $reason,
    ) {}

    public static function verified(VerifiedActor $actor): self
    {
        return new self($actor, null);
    }

    public static function failed(string $reason): self
    {
        return new self(null, $reason);
    }

    public function ok(): bool
    {
        return $this->actor !== null;
    }
}
