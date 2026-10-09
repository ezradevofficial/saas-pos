<?php

namespace Modules\POS\Sync;

use App\Core\Tenancy\Models\Device;

/**
 * AUTH-08: a manager's override.
 *
 * - redeem(): a manager's override (core shape: `{token}` online, or the
 *   offline signed form `{id, manager_user_id, cashier_user_id, permission,
 *   reference, authorised_at, signature}`), used once for `$reference`
 *   (the record's id). Null when it can't be verified here (no verifier
 *   yet): the action is then held for review. Throws a Rejection when the
 *   proof is invalid, expired, for something else, or replayed.
 *
 * Sign-in attestations (`actor_proof`, AUTH-07) go straight to core's
 * ActorProofVerifier (Authority).
 *
 * Bound to CoreOverrides (core's App\Core\Identity\Pin\OverrideVerifier).
 */
interface OverrideVerifier
{
    /** @param array<string, mixed> $override */
    public function redeem(Device $device, array $override, string $permission, string $reference): ?OverrideProof;
}
