<?php

namespace App\Core\Identity\Pin;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Sync\DeviceSecrets;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * AUTH-08: checks and uses a manager override that came with an action
 * from a device (a void, refund, price change or big discount). The POS
 * module calls redeem() inside the transaction that records the action,
 * with the permission the action needs and the record it applies to.
 *
 * Two forms:
 *
 *  - online, `['token' => 'ovr1....']` from POST pos/override
 *    (OverrideTokens): signature, tenant, device, permission and expiry are
 *    checked; a token naming a record must be used for that record.
 *
 *  - offline, when the device checked the manager's PIN itself:
 *    `['id', 'manager_user_id', 'cashier_user_id', 'permission',
 *    'reference', 'authorised_at', 'signature']`, the signature being
 *    base64url(HMAC-SHA256(device secret, message)) with message the lines
 *        override:v1
 *        {device id}
 *        {id}
 *        {manager_user_id}
 *        {cashier_user_id or empty}
 *        {permission}
 *        {reference or empty}
 *        {authorised_at, exactly as sent}
 *    joined by "\n" (no trailing newline). Offline overrides do not expire:
 *    the device may upload days later (NFR-04).
 *
 * Replay: each override id is used once (override_redemptions, unique).
 * Using it again for the same device, permission and record answers the
 * same (an idempotent re-upload, `firstUse` false); for anything else it
 * is refused (409 `override_replayed`). The first use is audited as
 * `core.user.override_redeem` on the manager, naming both users.
 */
class OverrideVerifier
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly DeviceSecrets $secrets,
        private readonly ScopeResolver $resolver,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array<string, mixed>  $override
     *
     * @throws ApiException 422 `override_invalid`, `override_expired`, `override_mismatch`; 409 `override_replayed`
     */
    public function redeem(Device $device, array $override, string $permission, ?string $reference): VerifiedOverride
    {
        $claims = isset($override['token']) && is_string($override['token'])
            ? $this->online($device, $override['token'], $permission, $reference)
            : $this->offline($device, $override, $permission, $reference);

        return DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->use($device, $claims));
    }

    /** The canonical message an offline override signs. */
    public static function offlineMessage(string $deviceId, string $id, string $managerId, ?string $cashierId, string $permission, ?string $reference, string $authorisedAt): string
    {
        return implode("\n", ['override:v1', $deviceId, $id, $managerId, $cashierId ?? '', $permission, $reference ?? '', $authorisedAt]);
    }

    /** @return array<string, mixed> */
    private function online(Device $device, string $token, string $permission, ?string $reference): array
    {
        $payload = OverrideTokens::read($token);

        if ($payload === null || ($payload['tid'] ?? null) !== $this->tenants->require() || ($payload['did'] ?? null) !== $device->id) {
            throw $this->invalid();
        }

        if ((int) ($payload['exp'] ?? 0) < CarbonImmutable::now()->getTimestamp()) {
            throw new ApiException(422, 'override_expired', __('auth.override.expired'));
        }

        if (($payload['perm'] ?? null) !== $permission || (($payload['ref'] ?? null) !== null && $payload['ref'] !== $reference)) {
            throw new ApiException(422, 'override_mismatch', __('auth.override.mismatch'));
        }

        return [
            'id' => (string) $payload['jti'],
            'mode' => OverrideRedemption::ONLINE,
            'manager' => (string) $payload['mid'],
            'cashier' => $payload['cid'] ?? null,
            'permission' => $permission,
            'reference' => $reference,
            'authorised_at' => CarbonImmutable::createFromTimestamp((int) $payload['iat']),
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function offline(Device $device, array $override, string $permission, ?string $reference): array
    {
        $field = fn (string $key) => isset($override[$key]) && is_string($override[$key]) && $override[$key] !== '' ? $override[$key] : null;
        [$id, $manager, $cashier, $signed, $signedReference, $at, $signature] = [
            $field('id'), $field('manager_user_id'), $field('cashier_user_id'), $field('permission'),
            $field('reference'), $field('authorised_at'), $field('signature'),
        ];

        if ($id === null || ! Str::isUuid($id) || $manager === null || ! Str::isUuid($manager) || ($cashier !== null && ! Str::isUuid($cashier))
            || $signed === null || $at === null || $signature === null) {
            throw $this->invalid();
        }

        try {
            $authorisedAt = CarbonImmutable::parse($at)->utc();
        } catch (Throwable) {
            throw $this->invalid();
        }

        $expected = $this->secrets->hmac($device, self::offlineMessage($device->id, $id, $manager, $cashier, $signed, $signedReference, $at));
        $given = base64_decode(strtr($signature, '-_', '+/'), true);

        if ($expected === null || ! is_string($given) || ! hash_equals($expected, $given)) {
            throw $this->invalid();
        }

        if ($signed !== $permission || $signedReference !== $reference) {
            throw new ApiException(422, 'override_mismatch', __('auth.override.mismatch'));
        }

        return [
            'id' => strtolower($id),
            'mode' => OverrideRedemption::OFFLINE,
            'manager' => $manager,
            'cashier' => $cashier,
            'permission' => $permission,
            'reference' => $reference,
            'authorised_at' => $authorisedAt,
        ];
    }

    /** @param array<string, mixed> $claims */
    private function use(Device $device, array $claims): VerifiedOverride
    {
        // Row-level security: users of another tenant are not found.
        $manager = User::query()->whereKey($claims['manager'])->first() ?? throw $this->invalid();

        if ($claims['cashier'] !== null && ! User::query()->whereKey($claims['cashier'])->exists()) {
            throw $this->invalid();
        }

        $rowId = (string) Str::uuid7();
        OverrideRedemption::query()->insertOrIgnore([
            'id' => $rowId,
            'tenant_id' => $device->tenant_id,
            'override_id' => $claims['id'],
            'mode' => $claims['mode'],
            'device_id' => $device->id,
            'manager_user_id' => $manager->id,
            'cashier_user_id' => $claims['cashier'],
            'permission' => $claims['permission'],
            'reference' => $claims['reference'],
            'authorised_at' => $claims['authorised_at'],
            'redeemed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = OverrideRedemption::query()->where('override_id', $claims['id'])->first();

        if ($row === null || $row->device_id !== $device->id || $row->permission !== $claims['permission']
            || $row->reference !== $claims['reference'] || $row->mode !== $claims['mode']) {
            throw new ApiException(409, 'override_replayed', __('auth.override.replayed'));
        }

        $firstUse = $row->id === $rowId;

        if ($firstUse) {
            $this->auditor->record('core.user.override_redeem', $manager, null, [
                'override_id' => $claims['id'],
                'mode' => $claims['mode'],
                'device_id' => $device->id,
                'manager_user_id' => $manager->id,
                'cashier_user_id' => $claims['cashier'],
                'permission' => $claims['permission'],
                'reference' => $claims['reference'],
                'authorised_at' => $row->authorised_at->toIso8601String(),
            ]);
        }

        return new VerifiedOverride(
            overrideId: $claims['id'],
            mode: $claims['mode'],
            managerUserId: $manager->id,
            cashierUserId: $row->cashier_user_id,
            permission: $claims['permission'],
            reference: $claims['reference'],
            authorisedAt: $row->authorised_at,
            managerHoldsPermission: $this->resolver->can($manager, $claims['permission'], Scope::location($device->location_id)),
            firstUse: $firstUse,
        );
    }

    private function invalid(): ApiException
    {
        return new ApiException(422, 'override_invalid', __('auth.override.invalid'));
    }
}
