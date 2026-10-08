<?php

namespace App\Core\Identity\Pin;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Sync\DeviceSecrets;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AUTH-06, AUTH-07: POS PINs and staff cards.
 *
 * Stored (UserPin): an Argon2id hash for checks on the server, and the
 * PBKDF2-HMAC-SHA256 key of the PIN (random 16-byte salt, `iterations`
 * from config `sync.pin_iterations`, at least 100,000, 32-byte output),
 * encrypted with the application key. Never the PIN.
 *
 * Sent to a device (material(), in the `staff` entity): the scheme, the
 * salt, the iterations and a verifier
 *     HMAC-SHA256(device secret, "pin:v1:{user_id}:" || PBKDF2 key)
 * (card: "card:v1:{user_id}:"). The device recomputes it from the PIN typed
 * and compares in constant time. Every device gets a different verifier;
 * without the device secret (kept in the platform keystore) the verifier
 * cannot be checked at all.
 *
 * Wrong attempts (DevicePinState) count per user and device, online
 * (verify()) and reported after offline attempts (report()). Reaching
 * `sync.pin_max_attempts` (5) locks the user's PIN on that device until a
 * new PIN is set. Lockouts are audited.
 */
class Pins
{
    public const SCHEME = 'pbkdf2-sha256+hmac-sha256/v1';

    public const PIN = 'pin';

    public const CARD = 'card';

    private const SALT_BYTES = 16;

    private const KEY_BYTES = 32;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly DeviceSecrets $secrets,
    ) {}

    /**
     * Set $user's PIN (validated by PinRules), and the card: a code sets
     * it, '' clears it, null keeps it. $by is the admin resetting it, or
     * null when users set their own. Clears every lockout of the user.
     */
    public function set(User $user, string $pin, ?string $card, ?User $by = null): UserPin
    {
        $byAdmin = $by !== null && $by->id !== $user->id;

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($user, $pin, $card, $by, $byAdmin) {
            $record = UserPin::query()->where('user_id', $user->id)->lockForUpdate()->first() ?? new UserPin(['user_id' => $user->id, 'version' => 0]);

            $record->fill(['pin_set_at' => now(), 'set_by' => $by?->id ?? $user->id, 'version' => $record->version + 1]);
            $record->fill($this->hashes(self::PIN, $pin));

            if ($card !== null) {
                $record->fill($card === '' ? $this->noHashes(self::CARD) : $this->hashes(self::CARD, PinRules::normaliseCard($card)));
            }

            $record->save();
            $this->clearLockouts($user);

            $this->auditor->record($byAdmin ? 'core.user.pin_reset' : 'core.user.pin_set', $user, null, [
                'pin_set' => true,
                'card_set' => $record->hasCard(),
                'version' => $record->version,
            ]);

            return $record;
        });
    }

    /** Remove $user's PIN and card: the user cannot sign in at a till until a new PIN is set. */
    public function clear(User $user, ?User $by = null): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($user, $by) {
            $record = UserPin::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($record === null || (! $record->hasPin() && ! $record->hasCard())) {
                return;
            }

            $record->fill([...$this->noHashes(self::PIN), ...$this->noHashes(self::CARD), 'version' => $record->version + 1, 'set_by' => $by?->id ?? $user->id])->save();
            $this->clearLockouts($user);
            $this->auditor->record('core.user.pin_clear', $user, ['pin_set' => true], ['pin_set' => false, 'card_set' => false, 'version' => $record->version]);
        });
    }

    /** @return array{pin_set: bool, card_set: bool, set_at: ?string} */
    public function status(User $user): array
    {
        $record = UserPin::query()->where('user_id', $user->id)->first();

        return [
            'pin_set' => (bool) $record?->hasPin(),
            'card_set' => (bool) $record?->hasCard(),
            'set_at' => $record?->hasPin() ? $record->pin_set_at?->toIso8601String() : null,
        ];
    }

    /**
     * What $device needs to check $kind offline, or null when the user has
     * none or the device has no secret.
     *
     * @return array{scheme: string, salt: string, iterations: int, verifier: string}|null
     */
    public function material(UserPin $record, Device $device, string $kind): ?array
    {
        $salt = $record->{"{$kind}_salt"};
        $key = $record->{"{$kind}_key"};

        if ($salt === null || $key === null) {
            return null;
        }

        $verifier = $this->secrets->hmac($device, self::verifierMessage($kind, $record->user_id, DeviceSecrets::decode($key)));

        return $verifier === null ? null : [
            'scheme' => self::SCHEME,
            'salt' => $salt,
            'iterations' => (int) $record->{"{$kind}_iterations"},
            'verifier' => DeviceSecrets::encode($verifier),
        ];
    }

    /** The message a verifier is the HMAC of: "pin:v1:{user_id}:" followed by the raw 32-byte key. */
    public static function verifierMessage(string $kind, string $userId, string $rawKey): string
    {
        return "{$kind}:v1:{$userId}:".$rawKey;
    }

    /**
     * Check $secret ($kind PIN or card) of $user on $device, counting a
     * wrong one. Throws 423 `pin_locked` when locked (now or by this
     * attempt), 422 `pin_incorrect` with `attempts_left`, 422 `pin_not_set`.
     */
    public function verify(Device $device, User $user, string $secret, string $kind = self::PIN): void
    {
        $max = self::maxAttempts();

        // The state row is locked while checking, so parallel guesses are counted one by one.
        $outcome = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($device, $user, $secret, $kind, $max) {
            $state = $this->lockedState($device, $user->id);

            if ($state->isLocked()) {
                return ['locked', 0];
            }

            $record = UserPin::query()->where('user_id', $user->id)->first();
            $hash = $record?->{"{$kind}_hash"};

            if ($hash === null) {
                return ['not_set', 0];
            }

            $given = $kind === self::CARD ? PinRules::normaliseCard($secret) : $secret;

            if (Hash::driver('argon2id')->check($given, $hash)) {
                if ($state->failed_attempts !== 0) {
                    $state->forceFill(['failed_attempts' => 0])->save();
                }

                return ['ok', 0];
            }

            $this->fail($state, $state->failed_attempts + 1, now(), false);

            return [$state->isLocked() ? 'locked' : 'incorrect', max(0, $max - $state->failed_attempts)];
        });

        match ($outcome[0]) {
            'ok' => null,
            'locked' => throw new ApiException(423, 'pin_locked', __('auth.pin.locked')),
            'not_set' => throw new ApiException(422, 'pin_not_set', __('auth.pin.not_set')),
            default => throw new ApiException(422, 'pin_incorrect', trans_choice('auth.pin.incorrect', $outcome[1], ['count' => $outcome[1]]), extra: ['attempts_left' => $outcome[1]]),
        };
    }

    /**
     * AUTH-06: the device reports its own count of consecutive wrong
     * attempts for a user (and whether it locked them) after working
     * offline. Reports only raise the count (max of both), so resending
     * one is harmless and a report can never unlock.
     *
     * @return array{user_id: string, failed_attempts: int, locked: bool}
     */
    public function report(Device $device, string $userId, int $failed, bool $locked, ?CarbonImmutable $at): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($device, $userId, $failed, $locked, $at) {
            $state = $this->lockedState($device, $userId);

            if (! $state->isLocked() && ($failed > $state->failed_attempts || $locked)) {
                $this->fail($state, max($failed, $state->failed_attempts), $at ?? now(), $locked);
            }

            return ['user_id' => $userId, 'failed_attempts' => $state->failed_attempts, 'locked' => $state->isLocked()];
        });
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('sync.pin_max_attempts', 5));
    }

    /** Record $count failures; lock (and audit) at the limit or when $lock. */
    private function fail(DevicePinState $state, int $count, mixed $at, bool $lock): void
    {
        $state->forceFill(['failed_attempts' => $count, 'last_failed_at' => $at]);

        if ($lock || $count >= self::maxAttempts()) {
            $state->locked_at = now();
        }

        $state->save();

        if ($state->isLocked()) {
            $this->auditor->record('core.user.pin_locked', User::query()->findOrFail($state->user_id), null, [
                'device_id' => $state->device_id,
                'failed_attempts' => $state->failed_attempts,
            ]);
        }
    }

    /** The state row of $userId on $device, created if needed, locked for the transaction. */
    private function lockedState(Device $device, string $userId): DevicePinState
    {
        // Foreign keys are checked without row-level security: never trust the id, look the user up here.
        User::query()->whereKey($userId)->firstOrFail();

        DevicePinState::query()->insertOrIgnore([
            'id' => (string) str()->uuid7(),
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'user_id' => $userId,
            'failed_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DevicePinState::query()->where('device_id', $device->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    private function clearLockouts(User $user): void
    {
        DevicePinState::query()->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('failed_attempts', '>', 0)->orWhereNotNull('locked_at'))
            ->update(['failed_attempts' => 0, 'locked_at' => null, 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function hashes(string $kind, string $secret): array
    {
        $salt = random_bytes(self::SALT_BYTES);
        $iterations = max(100000, (int) config('sync.pin_iterations'));

        return [
            "{$kind}_hash" => Hash::driver('argon2id')->make($secret),
            "{$kind}_salt" => DeviceSecrets::encode($salt),
            "{$kind}_iterations" => $iterations,
            "{$kind}_key" => DeviceSecrets::encode(hash_pbkdf2('sha256', $secret, $salt, $iterations, self::KEY_BYTES, true)),
        ];
    }

    /** @return array<string, null> */
    private function noHashes(string $kind): array
    {
        return ["{$kind}_hash" => null, "{$kind}_salt" => null, "{$kind}_iterations" => null, "{$kind}_key" => null];
    }
}
