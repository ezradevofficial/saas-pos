<?php

namespace App\Core\Sync;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-06, AUTH-08: each paired device's secrets (DeviceSecret), 32 random
 * bytes each, named by a key id (`kid`).
 *
 * The device receives the first one in the pairing answer and keeps it in
 * the platform keystore (Android Keystore / iOS Keychain through
 * SecureStore), apart from its SQLite database, like its token. The server
 * keeps them encrypted with the application key.
 *
 * Uses (HMAC-SHA256, told apart by the message prefix):
 *  - PIN and card verifiers: "pin:v1:{user_id}:" / "card:v1:{user_id}:"
 *    + the PBKDF2 key (Pins::material), under the current secret only;
 *  - offline manager overrides: "override:v2\n{device_id}\n{kid}\n..."
 *    (OverrideVerifier), under the secret named by kid.
 *
 * Rotation proves possession and survives a lost answer:
 *  1. GET sync/device-secret/challenge: a one-time nonce (5 minutes);
 *  2. POST sync/device-secret/rotate {kid, nonce, proof}, proof =
 *     HMAC(current secret, "rotate:v1\n{device_id}\n{nonce}"): a new
 *     *pending* secret and its kid (a lost answer is harmless: the current
 *     secret stays current, and a new rotation replaces the pending one);
 *  3. POST sync/device-secret/activate {kid, proof}, proof =
 *     HMAC(new secret, "activate:v1\n{device_id}\n{kid}"): the new secret
 *     becomes current and the old one is retired.
 * A device that lost its secret cannot rotate: it is unpaired and paired
 * again. Unpairing retires every secret.
 *
 * Whoever holds a device's current secret can sign offline overrides as
 * that device: the token and the secret are the device's identity.
 */
class DeviceSecrets
{
    public const BYTES = 32;

    public const CHALLENGE_SECONDS = 300;

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * At pairing: a new current secret, every earlier one retired.
     *
     * @return array{kid: string, secret: string}
     */
    public function issueFirst(Device $device): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($device) {
            $this->retireAll($device);
            $created = $this->create($device, DeviceSecret::CURRENT);
            $this->auditor->record('core.device.secret_issue', $device, null, ['kid' => $created['kid']]);
            SnapshotCache::bump($device->tenant_id);

            return $created;
        });
    }

    /** A one-time nonce for the next rotation, valid CHALLENGE_SECONDS. */
    public function challenge(Device $device): string
    {
        $nonce = self::encode(random_bytes(24));
        Cache::put(self::challengeKey($device), $nonce, self::CHALLENGE_SECONDS);

        return $nonce;
    }

    /**
     * Step 2: a new pending secret, after proof of the current one.
     *
     * @return array{kid: string, secret: string}
     */
    public function rotate(Device $device, string $kid, string $nonce, string $proof): array
    {
        $expected = Cache::pull(self::challengeKey($device));
        $current = $this->current($device);

        if (! is_string($expected) || ! hash_equals($expected, $nonce) || $current === null || $current->kid !== $kid
            || ! $this->proves($current, "rotate:v1\n{$device->id}\n{$nonce}", $proof)) {
            throw new ApiException(422, 'secret_proof_invalid', __('core.sync.secret_proof_invalid'));
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($device) {
            // A pending secret whose answer was lost is replaced.
            DeviceSecret::query()->where('device_id', $device->id)->where('status', DeviceSecret::PENDING)
                ->update(['status' => DeviceSecret::RETIRED, 'retired_at' => now(), 'updated_at' => now()]);
            $created = $this->create($device, DeviceSecret::PENDING);
            $this->auditor->record('core.device.secret_rotate', $device, null, ['kid' => $created['kid']]);

            return $created;
        });
    }

    /** Step 3: the pending secret becomes current once the device proves it holds it. */
    public function activate(Device $device, string $kid, string $proof): DeviceSecret
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($device, $kid, $proof) {
            $pending = DeviceSecret::query()->where('device_id', $device->id)->where('kid', $kid)
                ->where('status', DeviceSecret::PENDING)->lockForUpdate()->first();

            if ($pending === null || ! $this->proves($pending, "activate:v1\n{$device->id}\n{$kid}", $proof)) {
                throw new ApiException(422, 'secret_proof_invalid', __('core.sync.secret_proof_invalid'));
            }

            $before = $this->current($device)?->kid;
            DeviceSecret::query()->where('device_id', $device->id)->where('status', DeviceSecret::CURRENT)
                ->update(['status' => DeviceSecret::RETIRED, 'retired_at' => now(), 'updated_at' => now()]);
            $pending->forceFill(['status' => DeviceSecret::CURRENT, 'activated_at' => now()])->save();
            $this->auditor->record('core.device.secret_activate', $device, ['kid' => $before], ['kid' => $kid]);
            SnapshotCache::bump($device->tenant_id);

            return $pending;
        });
    }

    /** At unpairing: nothing stays current or pending. */
    public function retireAll(Device $device): void
    {
        DeviceSecret::query()->where('device_id', $device->id)->whereIn('status', [DeviceSecret::CURRENT, DeviceSecret::PENDING])
            ->update(['status' => DeviceSecret::RETIRED, 'retired_at' => now(), 'updated_at' => now()]);
        SnapshotCache::bump($device->tenant_id);
    }

    public function current(Device $device): ?DeviceSecret
    {
        return DeviceSecret::query()->where('device_id', $device->id)->where('status', DeviceSecret::CURRENT)->first();
    }

    /** The device's secret named $kid, whatever its status. */
    public function byKid(Device $device, string $kid): ?DeviceSecret
    {
        return DeviceSecret::query()->where('device_id', $device->id)->where('kid', $kid)->first();
    }

    public static function hmac(DeviceSecret $secret, string $message): string
    {
        return hash_hmac('sha256', $message, $secret->raw(), true);
    }

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    private function proves(DeviceSecret $secret, string $message, string $proof): bool
    {
        return hash_equals(self::hmac($secret, $message), self::decode($proof));
    }

    /** @return array{kid: string, secret: string} */
    private function create(Device $device, string $status): array
    {
        $raw = random_bytes(self::BYTES);
        $kid = bin2hex(random_bytes(8));

        DeviceSecret::create([
            'device_id' => $device->id,
            'kid' => $kid,
            'secret' => self::encode($raw),
            'status' => $status,
            'issued_at' => now(),
            'activated_at' => $status === DeviceSecret::CURRENT ? now() : null,
        ]);

        return ['kid' => $kid, 'secret' => self::encode($raw)];
    }

    private static function challengeKey(Device $device): string
    {
        return "device-secret-challenge:{$device->tenant_id}:{$device->id}";
    }
}
