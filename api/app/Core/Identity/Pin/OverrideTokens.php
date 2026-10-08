<?php

namespace App\Core\Identity\Pin;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * AUTH-08: online manager overrides. After the manager's PIN is checked on
 * the cashier's device (Pins::verify), the server signs a short-lived token
 *
 *     "ovr1." + base64url(payload JSON) + "." + base64url(HMAC-SHA256(k, "ovr1." + base64url(payload JSON)))
 *
 * with k = HMAC-SHA256(application key, "pos-override-token:v1"). The
 * payload: v (1), jti (the override id), tid (tenant), did (device), mid
 * (manager), cid (cashier or null), perm (the permission), ref (the
 * record, or null), iat and exp (Unix seconds, `sync.override_ttl_seconds`
 * apart). The device sends the token with the action it authorises; the
 * POS module redeems it with OverrideVerifier. Issuing is audited as
 * `core.user.override_issue` on the manager.
 */
class OverrideTokens
{
    public const PREFIX = 'ovr1';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    /** @return array{token: string, override_id: string, expires_at: string} */
    public function issue(Device $device, User $manager, ?string $cashierId, string $permission, ?string $reference): array
    {
        $now = CarbonImmutable::now();
        $expires = $now->addSeconds(max(10, (int) config('sync.override_ttl_seconds', 120)));
        $id = (string) Str::uuid7();

        $payload = [
            'v' => 1,
            'jti' => $id,
            'tid' => $this->tenants->require(),
            'did' => $device->id,
            'mid' => $manager->id,
            'cid' => $cashierId,
            'perm' => $permission,
            'ref' => $reference,
            'iat' => $now->getTimestamp(),
            'exp' => $expires->getTimestamp(),
        ];

        $body = self::PREFIX.'.'.self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->auditor->record('core.user.override_issue', $manager, null, [
            'override_id' => $id,
            'device_id' => $device->id,
            'cashier_user_id' => $cashierId,
            'permission' => $permission,
            'reference' => $reference,
        ]);

        return ['token' => $body.'.'.self::b64(self::sign($body)), 'override_id' => $id, 'expires_at' => $expires->toIso8601String()];
    }

    /**
     * The payload of a token whose signature is right, else null. Expiry
     * and binding are the verifier's to check.
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $signature = base64_decode(strtr($parts[2], '-_', '+/'), true);

        if (! is_string($signature) || ! hash_equals(self::sign($parts[0].'.'.$parts[1]), $signature)) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($payload) && ($payload['v'] ?? null) === 1 ? $payload : null;
    }

    private static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::key(), true);
    }

    private static function key(): string
    {
        $appKey = (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        return hash_hmac('sha256', 'pos-override-token:v1', $raw, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
