<?php

namespace App\Core\Identity\Pin;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AUTH-07: records a till sign-in the server checked online, so that the
 * device's sign-in attestation for that session verifies as `online`
 * (ActorProofVerifier). Idempotent per device and session: a resend for
 * the same user answers the same; a session id already recorded for
 * another user on that device is refused (409 `till_session_conflict`).
 * The first record is audited as `core.user.till_sign_in` (AUD-01).
 */
class TillSignIns
{
    public function __construct(private readonly Auditor $auditor) {}

    public function record(Device $device, User $user, string $sessionId, ?string $signedInAt): TillSignIn
    {
        $sessionId = strtolower($sessionId);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($device, $user, $sessionId, $signedInAt) {
            $rowId = (string) Str::uuid7();
            TillSignIn::query()->insertOrIgnore([
                'id' => $rowId,
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'signed_in_at' => $signedInAt,
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = TillSignIn::query()->where('device_id', $device->id)->where('session_id', $sessionId)->firstOrFail();

            if ($row->user_id !== $user->id) {
                throw new ApiException(409, 'till_session_conflict', __('auth.pin.session_conflict'));
            }

            if ($row->id === $rowId) {
                $this->auditor->record('core.user.till_sign_in', $user, null, [
                    'device_id' => $device->id,
                    'session_id' => $sessionId,
                    'signed_in_at' => $signedInAt,
                    'verified_at' => $row->verified_at->toIso8601String(),
                ]);
            }

            return $row;
        });
    }
}
