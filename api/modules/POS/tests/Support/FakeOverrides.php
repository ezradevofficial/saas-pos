<?php

namespace Modules\POS\Tests\Support;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;
use Modules\POS\Sync\OverrideProof;
use Modules\POS\Sync\OverrideVerifier;
use Modules\POS\Sync\Rejection;

/**
 * A verifier for module tests: an override signed `valid` is proven for
 * its manager, `bad` is refused, anything else can't be verified; an
 * `actor_proof` of `attested` proves the person. Overrides are remembered
 * per reference, so a resend answers as already recorded.
 */
class FakeOverrides implements OverrideVerifier
{
    public const VALID = 'valid';

    public const ATTESTED = 'attested';

    public function redeem(Device $device, array $override, string $permission, string $reference): ?OverrideProof
    {
        return match ($override['signature'] ?? null) {
            self::VALID => new OverrideProof((string) $override['manager_user_id'], $override['cashier_user_id'] ?? null, qualifies: true, offline: ($override['mode'] ?? null) === 'offline'),
            'bad' => throw new Rejection('override_invalid', 'override'),
            default => null,
        };
    }

    public function actor(Device $device, User $user, ?string $proof, string $reference): bool
    {
        return $proof === self::ATTESTED;
    }
}
