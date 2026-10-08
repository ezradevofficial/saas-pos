<?php

namespace App\Core\Tenancy;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Sync\DeviceSecrets;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * TEN-05: pairing a POS device. An admin issues a one-time 8-character code
 * (shown once, stored as a sha256 hash, valid 15 minutes); the device sends
 * it to the public pair endpoint and receives its own token (ability
 * `device`). Unpairing revokes the device's tokens, from active or suspended
 * (a lost or stolen device is unpaired without being resumed first); a
 * suspended device's tokens are refused until it is resumed. Pair, suspend,
 * resume and unpair are audited (AUD-01).
 */
class DevicePairing
{
    /** No 0/O, 1/I or L: codes are read aloud and typed on a till. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    public const VALID_MINUTES = 15;

    private const PAIRABLE = [Device::STATUS_PENDING, Device::STATUS_UNPAIRED];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly AuditContext $auditContext,
        private readonly Archiver $archiver,
        private readonly DeviceSecrets $secrets,
    ) {}

    /**
     * A new code for $device, replacing any earlier one.
     *
     * @return array{code: string, expires_at: CarbonInterface}
     */
    public function issueCode(Device $device): array
    {
        if (! in_array($device->status, self::PAIRABLE, true)) {
            throw new ApiException(422, 'device_not_pairable', __('core.devices.not_pairable'));
        }

        for ($attempt = 1; ; $attempt++) {
            $code = self::generate();
            $expiresAt = now()->addMinutes(self::VALID_MINUTES);

            try {
                $device->forceFill([
                    'pairing_code_hash' => self::hash($code),
                    'pairing_code_expires_at' => $expiresAt,
                ])->save();

                return ['code' => $code, 'expires_at' => $expiresAt];
            } catch (UniqueConstraintViolationException $e) {
                // Another device holds the same code (unique across tenants).
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Pair the device holding $code and return its token. The tenant is
     * found by auth_tenant_for_pairing (security definer); everything else
     * runs under that tenant's row-level security.
     *
     * The answer carries the device's own secret (AUTH-06, AUTH-08,
     * DeviceSecrets), shown this once.
     *
     * @return array{token: string, device: Device, secret: string, kid: string}
     */
    public function pair(string $code, string $deviceName, ?string $ip, ?string $userAgent): array
    {
        $code = self::normalise($code);
        $invalid = fn () => new ApiException(422, 'invalid_pairing_code', __('core.devices.invalid_pairing_code'));

        if (preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) !== 1) {
            throw $invalid();
        }

        $hash = self::hash($code);
        $tenantId = DB::selectOne('select auth_tenant_for_pairing(?) as tenant_id', [$hash])?->tenant_id;

        if ($tenantId === null) {
            throw $invalid();
        }

        return $this->tenants->run($tenantId, fn () => DB::transaction(function () use ($hash, $deviceName, $ip, $userAgent, $invalid) {
            $device = Device::where('pairing_code_hash', $hash)
                ->where('pairing_code_expires_at', '>', now())
                ->whereIn('status', self::PAIRABLE)
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                throw $invalid();
            }

            $this->auditContext->setDeviceId($device->id)->setLocationId($device->location_id);

            $this->transition($device, 'pair', [
                'status' => Device::STATUS_ACTIVE,
                'paired_at' => now(),
            ]);

            $secret = $this->secrets->issueFirst($device);

            return [
                'token' => $device->issueToken($deviceName, $ip, $userAgent)->plainTextToken,
                'device' => $device,
                'secret' => $secret['secret'],
                'kid' => $secret['kid'],
            ];
        }));
    }

    /**
     * Block the device: its tokens stay but are refused while it is
     * suspended (see IdentityServiceProvider), so resume() restores it
     * without pairing again. No change, no audit entry.
     */
    public function suspend(Device $device): Device
    {
        return $this->locked($device, function (Device $device) {
            if ($device->status === Device::STATUS_SUSPENDED) {
                return $device;
            }

            return $this->transition($device, 'suspend', ['status' => Device::STATUS_SUSPENDED], revokeTokens: false);
        });
    }

    /**
     * Lift a suspension (`core.device.archive`), keeping the pairing. Never
     * under an archived location (422 `parent_archived`): the location row
     * is locked so it cannot be archived meanwhile (TEN-06).
     */
    public function resume(Device $device): Device
    {
        return $this->locked($device, function (Device $device) {
            if ($device->status === Device::STATUS_ACTIVE) {
                return $device;
            }

            if ($device->status !== Device::STATUS_SUSPENDED) {
                throw new ApiException(422, 'device_not_suspended', __('core.devices.not_suspended'));
            }

            $this->archiver->lockActive(Location::class, $device->location_id);

            // A device suspended before it was ever paired goes back to pending.
            $status = $device->paired_at !== null ? Device::STATUS_ACTIVE : Device::STATUS_PENDING;

            return $this->transition($device, 'resume', ['status' => $status], revokeTokens: false);
        });
    }

    /**
     * Revoke the device's tokens and pairing (`core.device.pair`), from
     * active, pending or suspended. A suspended device is never resumed on
     * the way: its tokens are deleted while it is still refused, so the old
     * token never authenticates again.
     */
    public function unpair(Device $device): Device
    {
        return $this->locked($device, function (Device $device) {
            if ($device->status === Device::STATUS_UNPAIRED) {
                return $device;
            }

            // The secrets go with the pairing: the device's PIN verifiers stop working (DeviceSecrets).
            $this->secrets->retireAll($device);

            return $this->transition($device, 'unpair', ['status' => Device::STATUS_UNPAIRED, 'paired_at' => null]);
        });
    }

    /** Run $fn on $device's row locked for the transaction, with fresh values. */
    private function locked(Device $device, callable $fn): Device
    {
        return DB::transaction(function () use ($device, $fn) {
            $fresh = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $device->setRawAttributes($fresh->getAttributes(), true);

            return $fn($device);
        });
    }

    /**
     * Apply $changes, clear any pairing code, optionally revoke the device's
     * tokens and record one `core.device.{verb}` entry, in one transaction.
     */
    private function transition(Device $device, string $verb, array $changes, bool $revokeTokens = true): Device
    {
        return DB::transaction(function () use ($device, $verb, $changes, $revokeTokens) {
            $changes += ['pairing_code_hash' => null, 'pairing_code_expires_at' => null];
            $logged = array_keys(array_diff_key($changes, array_flip($device->getHidden())));

            $before = $device->only($logged);

            if ($revokeTokens) {
                $device->tokens()->delete();
            }

            // The transition is the audit entry; no separate update entry.
            $device->forceFill($changes)->saveQuietly();

            $this->auditor->record("core.device.{$verb}", $device, $before, $device->only($logged));

            return $device;
        });
    }

    private static function generate(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** Upper case, without spaces or dashes a person may type. */
    private static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $code));
    }

    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
