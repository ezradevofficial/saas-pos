<?php

namespace Modules\POS\Tests\Support;

use App\Core\Tenancy\Models\Device;
use Modules\POS\Sync\OverrideProof;
use Modules\POS\Sync\OverrideVerifier;
use Modules\POS\Sync\Rejection;

/**
 * A verifier for module tests: an override signed `valid` is proven for
 * its manager, `bad` is refused, anything else can't be verified.
 * Sign-in attestations (`actor_proof`) are real: BuildsPos::actorProof
 * signs them with the till's secret and core verifies them.
 */
class FakeOverrides implements OverrideVerifier
{
    public const VALID = 'valid';

    public function redeem(Device $device, array $override, string $permission, string $reference): ?OverrideProof
    {
        return match ($override['signature'] ?? null) {
            self::VALID => new OverrideProof((string) $override['manager_user_id'], $override['cashier_user_id'] ?? null, qualifies: true, offline: ($override['mode'] ?? null) === 'offline'),
            'bad' => throw new Rejection('override_invalid', 'override'),
            default => null,
        };
    }
}
