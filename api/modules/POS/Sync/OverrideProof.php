<?php

namespace Modules\POS\Sync;

/**
 * AUTH-08: a verified manager override, as the core verifier reports it.
 * `qualifies` is false when the manager is no longer active, no longer
 * works at the device's location or no longer holds the permission there;
 * `offline` overrides (the device checked the PIN itself) are applied but
 * flagged for review.
 */
final class OverrideProof
{
    public function __construct(
        public readonly string $managerUserId,
        public readonly ?string $cashierUserId,
        public readonly bool $qualifies = true,
        public readonly bool $offline = false,
    ) {}
}
