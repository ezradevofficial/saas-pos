<?php

namespace App\Core\Identity\Pin;

use App\Core\Identity\Models\User;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\DeviceSecrets;
use App\Core\Sync\StaffDirectory;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * AUTH-07: checks a till sign-in attestation (`actor_proof`), the proof a
 * device attaches to what it uploads that a staff member was signed in
 * when the record was made.
 *
 * The device signs it when someone signs in at the till, whether it
 * checked the PIN itself (offline) or through POST pos/pin/verify:
 *     {session_id, user_id, signed_in_at, kid, signature}
 * with signature = base64url, no padding, of HMAC-SHA256(device secret
 * `kid`, message), message being the lines
 *     signin:v1
 *     {device id}
 *     {kid}
 *     {session_id}
 *     {user_id}
 *     {signed_in_at, exactly as sent}
 * joined by "\n" (no trailing newline). `session_id` is a UUID the device
 * makes for each sign-in; `signed_in_at` an ISO 8601 time with a `Z` or an
 * offset (the server's clock as the device knows it).
 *
 * Verified when: every field is present and well formed (no CR or LF);
 * `kid` names a secret of this device; the signature matches (constant
 * time); `signed_in_at` falls while that secret was current, with the
 * override clock skew (DeviceSecrets::currentAt); the user is the one the
 * record names, exists in the tenant (row-level security), is active, and
 * is staff of the device's location holding the till sign-in permission
 * (StaffDirectory, the same rule as the staff entity and PIN checks).
 *
 * `online` is true when the server checked that sign-in itself: a
 * successful POST pos/pin/verify on the same device for the same user
 * with this `session_id` (TillSignIns). Otherwise the proof is the
 * device's claim, like an offline override: whoever holds the device
 * secret could have signed it, so callers may flag it for review.
 *
 * A proof is not bound to a record: it proves the sign-in, and every
 * record made during that sign-in carries it.
 */
class ActorProofVerifier
{
    public const VERSION = 'signin:v1';

    public const FAIL_MALFORMED = 'malformed';

    public const FAIL_UNKNOWN_KEY = 'unknown_key';

    public const FAIL_SIGNATURE = 'bad_signature';

    public const FAIL_OUTSIDE_WINDOW = 'outside_key_window';

    public const FAIL_WRONG_USER = 'wrong_user';

    public const FAIL_USER_UNKNOWN = 'user_unknown';

    public const FAIL_USER_INACTIVE = 'user_inactive';

    public const FAIL_NOT_STAFF = 'not_staff_here';

    /** The session was recorded online for another user or another sign-in time. */
    public const FAIL_SESSION_MISMATCH = 'session_mismatch';

    /** ISO 8601 date and time with a `Z` or an offset (also for offline overrides, OverrideVerifier). */
    public const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}(:?\d{2})?)$/';

    public function __construct(
        private readonly DeviceSecrets $secrets,
        private readonly StaffDirectory $staff,
    ) {}

    /** The canonical message a sign-in attestation signs. */
    public static function message(string $deviceId, string $kid, string $sessionId, string $userId, string $signedInAt): string
    {
        return implode("\n", [self::VERSION, $deviceId, $kid, $sessionId, $userId, $signedInAt]);
    }

    /**
     * @param  array<string, mixed>  $proof  {session_id, user_id, signed_in_at, kid, signature}
     * @param  string|null  $expectedUserId  the user the record names as having acted (null: any)
     */
    public function verify(Device $device, array $proof, ?string $expectedUserId): ActorProofResult
    {
        $field = fn (string $key) => isset($proof[$key]) && is_string($proof[$key]) && $proof[$key] !== '' && strpbrk($proof[$key], "\r\n") === false ? $proof[$key] : null;
        [$sessionId, $userId, $at, $kid, $signature] = [$field('session_id'), $field('user_id'), $field('signed_in_at'), $field('kid'), $field('signature')];

        if ($sessionId === null || ! Str::isUuid($sessionId) || $userId === null || ! Str::isUuid($userId)
            || $at === null || preg_match(self::TIME, $at) !== 1 || $kid === null || $signature === null) {
            return ActorProofResult::failed(self::FAIL_MALFORMED);
        }

        try {
            $signedInAt = CarbonImmutable::parse($at)->utc();
        } catch (Throwable) {
            return ActorProofResult::failed(self::FAIL_MALFORMED);
        }

        $secret = $this->secrets->byKid($device, $kid);

        if ($secret === null) {
            return ActorProofResult::failed(self::FAIL_UNKNOWN_KEY);
        }

        $given = DeviceSecrets::decode($signature);
        $expected = DeviceSecrets::hmac($secret, self::message($device->id, $kid, $sessionId, $userId, $at));

        if (! hash_equals($expected, $given)) {
            return ActorProofResult::failed(self::FAIL_SIGNATURE);
        }

        if (! DeviceSecrets::currentAt($secret, $signedInAt)) {
            return ActorProofResult::failed(self::FAIL_OUTSIDE_WINDOW);
        }

        if ($expectedUserId !== null && strtolower($expectedUserId) !== strtolower($userId)) {
            return ActorProofResult::failed(self::FAIL_WRONG_USER);
        }

        // Row-level security: another tenant's user is not found.
        $user = User::query()->find($userId);

        if ($user === null) {
            return ActorProofResult::failed(self::FAIL_USER_UNKNOWN);
        }

        if (! $user->isActive()) {
            return ActorProofResult::failed(self::FAIL_USER_INACTIVE);
        }

        if ($this->staff->at(DeviceScope::of($device), $user->id) === []) {
            return ActorProofResult::failed(self::FAIL_NOT_STAFF);
        }

        // A session the server recorded online must be the one the proof describes: same person,
        // same sign-in time as sent to pos/pin/verify. Anything else is a forged or replayed claim.
        $session = TillSignIn::query()->where('device_id', $device->id)->where('session_id', strtolower($sessionId))->first();

        if ($session !== null && ($session->user_id !== $user->id || ($session->signed_in_at !== null && $session->signed_in_at !== $at))) {
            return ActorProofResult::failed(self::FAIL_SESSION_MISMATCH);
        }

        $online = $session !== null && $session->signed_in_at !== null;

        return ActorProofResult::verified(new VerifiedActor($user, strtolower($sessionId), $signedInAt, $online));
    }
}
