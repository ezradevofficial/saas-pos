<?php

namespace Modules\POS\Sync;

/** AUTH-08: a verified manager override, as the core verifier reports it. */
final class OverrideProof
{
    public function __construct(
        public readonly string $managerUserId,
        public readonly ?string $cashierUserId,
        public readonly bool $managerHoldsPermission,
        public readonly bool $firstUse,
    ) {}
}
