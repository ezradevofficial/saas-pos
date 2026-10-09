<?php

namespace Modules\POS\Sync;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;

/**
 * AUTH-07, AUTH-08: proof of who acted at the till.
 *
 * - redeem(): a manager's override (core shape: `{token}` online, or the
 *   offline signed form `{id, manager_user_id, cashier_user_id, permission,
 *   reference, authorised_at, signature}`), used once for `$reference`
 *   (the record's id). Null when it can't be verified here (no verifier
 *   yet): the action is then held for review. Throws a Rejection when the
 *   proof is invalid, expired, for something else, or replayed.
 * - actor(): a device-signed sign-in attestation (`actor_proof`) that
 *   $user was signed in on the till for $reference. False when absent or
 *   not verifiable.
 *
 * Bound to CoreOverrides (core's App\Core\Identity\Pin\OverrideVerifier).
 */
interface OverrideVerifier
{
    /** @param array<string, mixed> $override */
    public function redeem(Device $device, array $override, string $permission, string $reference): ?OverrideProof;

    public function actor(Device $device, User $user, ?string $proof, string $reference): bool;
}
