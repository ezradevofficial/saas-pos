<?php

namespace App\Core\Sync;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-06, AUTH-08: each paired device's own 32-byte secret. The device
 * receives it once (in the pairing answer, or from a rotation it asks for)
 * and keeps it in the platform's secure storage (Android Keystore / iOS
 * Keychain), apart from its SQLite database. The server keeps it encrypted
 * with the application key.
 *
 * It keys two HMAC-SHA256 uses, told apart by the message prefix:
 *  - PIN and card verifiers: "pin:v1:{user_id}:" / "card:v1:{user_id}:"
 *    followed by the 32-byte PBKDF2 key (Pins::material), so the PIN
 *    material in the device database is useless without the secret, and
 *    differs on every device;
 *  - offline manager override signatures: "override:v1\n..."
 *    (OverrideVerifier).
 *
 * Unpairing clears it. Rotating replaces it: verifiers change (the staff
 * snapshot is sent again) and overrides signed with the old secret no
 * longer verify, so a device uploads its pending sales before rotating.
 */
class DeviceSecrets
{
    public const BYTES = 32;

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * A new secret for $device (replacing any), base64url without padding.
     * Shown to the device once.
     */
    public function issue(Device $device): string
    {
        $raw = random_bytes(self::BYTES);

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($device, $raw) {
            $rotated = $device->secret !== null;
            $device->forceFill(['secret' => self::encode($raw), 'secret_issued_at' => now()])->saveQuietly();
            $this->auditor->record($rotated ? 'core.device.secret_rotate' : 'core.device.secret_issue', $device);
        });

        return self::encode($raw);
    }

    /** The raw secret, or null when the device has none. */
    public function key(Device $device): ?string
    {
        return $device->secret === null ? null : self::decode($device->secret);
    }

    /** HMAC-SHA256 of $message under the device's secret (raw bytes), or null without one. */
    public function hmac(Device $device, string $message): ?string
    {
        $key = $this->key($device);

        return $key === null ? null : hash_hmac('sha256', $message, $key, true);
    }

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
