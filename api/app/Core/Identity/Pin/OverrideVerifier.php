<?php

namespace App\Core\Identity\Pin;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\DeviceSecrets;
use App\Core\Sync\StaffDirectory;
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
 * with the permission the action needs and the record it applies to
 * (`reference`: the sale or line id, required).
 *
 * Two forms:
 *
 *  - online, `['token' => 'ovr1....']` from POST pos/override
 *    (OverrideTokens): signature, tenant, device, permission, record and
 *    expiry are checked.
 *
 *  - offline, when the device checked the manager's PIN itself:
 *    `['id', 'kid', 'manager_user_id', 'cashier_user_id', 'permission',
 *    'reference', 'authorised_at', 'signature']`, the signature being
 *    base64url(HMAC-SHA256(device secret `kid`, message)) with message the
 *    lines
 *        override:v2
 *        {device id}
 *        {kid}
 *        {id}
 *        {manager_user_id}
 *        {cashier_user_id or empty}
 *        {permission}
 *        {reference}
 *        {authorised_at, exactly as sent}
 *    joined by "\n" (no trailing newline). `authorised_at` must fall while
 *    that secret was current (from its issue to its retirement or now),
 *    give or take `sync.override_clock_skew_seconds`. Offline overrides do
 *    not otherwise expire: the device may upload days later (NFR-04). They
 *    are the device's claim: whoever holds its secret can sign them, so the
 *    POS module records them for review (`mode` offline, and the flags).
 *
 * Replay: each override id is used once (override_redemptions, unique per
 * tenant). Using it again for the same device, permission and record
 * throws OverrideAlreadyApplied (409 `override_already_applied`, carrying
 * the earlier result): the caller must treat it as "this action was
 * already recorded", never as a fresh approval. Anything else is refused
 * (409 `override_replayed`). The first use is audited as
 * `core.user.override_redeem` on the manager, naming both users.
 *
 * The result says whether the manager is still active, still works at the
 * device's location, and still holds the permission there: an offline
 * override stays evidence of what happened at the till (the device wins
 * for completed sales), but one by someone who has since lost any of these
 * is flagged for review.
 */
class OverrideVerifier
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly DeviceSecrets $secrets,
        private readonly ScopeResolver $resolver,
        private readonly StaffDirectory $staff,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array<string, mixed>  $override
     *
     * @throws OverrideAlreadyApplied 409 `override_already_applied`
     * @throws ApiException 422 `override_invalid`, `override_expired`, `override_mismatch`, `override_reference_required`; 409 `override_replayed`
     */
    public function redeem(Device $device, array $override, string $permission, string $reference): VerifiedOverride
    {
        if (trim($reference) === '') {
            throw new ApiException(422, 'override_reference_required', __('auth.override.reference_required'));
        }

        $claims = isset($override['token']) && is_string($override['token'])
            ? $this->online($device, $override['token'], $permission, $reference)
            : $this->offline($device, $override, $permission, $reference);

        $result = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->use($device, $claims));

        if (! $result['first']) {
            throw new OverrideAlreadyApplied($result['override']);
        }

        return $result['override'];
    }

    /** The canonical message an offline override signs. */
    public static function offlineMessage(string $deviceId, string $kid, string $id, string $managerId, ?string $cashierId, string $permission, string $reference, string $authorisedAt): string
    {
        return implode("\n", ['override:v2', $deviceId, $kid, $id, $managerId, $cashierId ?? '', $permission, $reference, $authorisedAt]);
    }

    /** @return array<string, mixed> */
    private function online(Device $device, string $token, string $permission, string $reference): array
    {
        $payload = OverrideTokens::read($token);

        if ($payload === null || ($payload['tid'] ?? null) !== $this->tenants->require() || ($payload['did'] ?? null) !== $device->id) {
            throw $this->invalid();
        }

        if ((int) ($payload['exp'] ?? 0) < CarbonImmutable::now()->getTimestamp()) {
            throw new ApiException(422, 'override_expired', __('auth.override.expired'));
        }

        if (($payload['perm'] ?? null) !== $permission || ($payload['ref'] ?? null) !== $reference) {
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
    private function offline(Device $device, array $override, string $permission, string $reference): array
    {
        $field = fn (string $key) => isset($override[$key]) && is_string($override[$key]) && $override[$key] !== '' ? $override[$key] : null;
        [$id, $kid, $manager, $cashier, $signed, $signedReference, $at, $signature] = [
            $field('id'), $field('kid'), $field('manager_user_id'), $field('cashier_user_id'), $field('permission'),
            $field('reference'), $field('authorised_at'), $field('signature'),
        ];

        // The signed message is one field per line: a line break in a field, or a time PHP would
        // read loosely ("now", no zone), is never a valid override.
        if ($id === null || ! Str::isUuid($id) || $kid === null || $manager === null || ! Str::isUuid($manager) || ($cashier !== null && ! Str::isUuid($cashier))
            || $signed === null || $signedReference === null || $at === null || $signature === null
            || preg_match(ActorProofVerifier::TIME, $at) !== 1 || preg_match('/[\r\n]/', $signed.$signedReference.$kid) === 1) {
            throw $this->invalid();
        }

        try {
            $authorisedAt = CarbonImmutable::parse($at)->utc();
        } catch (Throwable) {
            throw $this->invalid();
        }

        $secret = $this->secrets->byKid($device, $kid);
        $given = base64_decode(strtr($signature, '-_', '+/'), true);
        $expected = $secret === null ? null : DeviceSecrets::hmac($secret, self::offlineMessage($device->id, $kid, $id, $manager, $cashier, $signed, $signedReference, $at));

        if ($expected === null || ! is_string($given) || ! hash_equals($expected, $given) || ! DeviceSecrets::currentAt($secret, $authorisedAt)) {
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

    /**
     * @param  array<string, mixed>  $claims
     * @return array{first: bool, override: VerifiedOverride}
     */
    private function use(Device $device, array $claims): array
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

        $first = $row->id === $rowId;
        $location = Scope::location($device->location_id);

        $verified = new VerifiedOverride(
            overrideId: $claims['id'],
            mode: $claims['mode'],
            managerUserId: $manager->id,
            cashierUserId: $row->cashier_user_id,
            permission: $claims['permission'],
            reference: $claims['reference'],
            authorisedAt: $row->authorised_at,
            managerActive: $manager->isActive(),
            managerStaffAtLocation: $this->staff->at(DeviceScope::of($device), $manager->id) !== [],
            managerHoldsPermission: $this->resolver->can($manager, $claims['permission'], $location),
        );

        if ($first) {
            $this->auditor->record('core.user.override_redeem', $manager, null, [
                'override_id' => $claims['id'],
                'mode' => $claims['mode'],
                'device_id' => $device->id,
                'manager_user_id' => $manager->id,
                'cashier_user_id' => $claims['cashier'],
                'permission' => $claims['permission'],
                'reference' => $claims['reference'],
                'authorised_at' => $row->authorised_at->toIso8601String(),
                'manager_active' => $verified->managerActive,
                'manager_staff_at_location' => $verified->managerStaffAtLocation,
                'manager_holds_permission' => $verified->managerHoldsPermission,
            ]);
        }

        return ['first' => $first, 'override' => $verified];
    }

    private function invalid(): ApiException
    {
        return new ApiException(422, 'override_invalid', __('auth.override.invalid'));
    }
}
