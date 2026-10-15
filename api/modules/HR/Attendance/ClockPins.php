<?php

namespace Modules\HR\Attendance;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\Pins;
use App\Core\Identity\Pin\UserPin;
use App\Core\Sync\DeviceSecret;
use App\Core\Sync\DeviceSecrets;
use App\Core\Sync\SnapshotCache;
use Illuminate\Support\Facades\Hash;
use Modules\HR\Models\ClockPin;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Errors;

/**
 * HR-23: clock-in PINs, kept as the core PIN scheme keeps till PINs
 * (AUTH-06): an Argon2id hash for the server, and the PBKDF2-HMAC-SHA256
 * key of the PIN (random 16-byte salt, `sync.pin_iterations`, 32 bytes)
 * encrypted with the application key; never the PIN. A device gets, per
 * employee, the salt, the iterations and a verifier
 *     HMAC-SHA256(device secret, "hr-clock:v1:{employee_id}:" || key)
 * under its current secret, so it checks a typed PIN offline. Set and
 * cleared PINs are audited on the employee without any of these values.
 */
class ClockPins
{
    public const PREFIX = 'hr-clock';

    private const SALT_BYTES = 16;

    private const KEY_BYTES = 32;

    public function __construct(private readonly Auditor $auditor) {}

    public function set(Employee $employee, string $pin, User $by): ClockPin
    {
        if (preg_match('/^\d{4,6}\z/', $pin) !== 1) {
            throw Errors::make(422, 'clock_pin_format', field: 'pin');
        }

        // HR-23: never the employee's till sign-in PIN (one leaked code must not open both).
        $tillHash = $employee->user_id === null ? null : UserPin::query()->where('user_id', $employee->user_id)->value('pin_hash');

        if ($tillHash !== null && Hash::driver('argon2id')->check($pin, $tillHash)) {
            throw Errors::make(422, 'clock_pin_same_as_till', field: 'pin');
        }

        return Errors::transaction(function () use ($employee, $pin, $by) {
            $record = ClockPin::query()->where('employee_id', $employee->id)->lockForUpdate()->first() ?? new ClockPin(['employee_id' => $employee->id, 'version' => 0]);
            $salt = random_bytes(self::SALT_BYTES);
            $iterations = max(100000, (int) config('sync.pin_iterations'));

            $record->fill([
                'pin_hash' => Hash::driver('argon2id')->make($pin),
                'pin_salt' => DeviceSecrets::encode($salt),
                'pin_iterations' => $iterations,
                'pin_key' => DeviceSecrets::encode(hash_pbkdf2('sha256', $pin, $salt, $iterations, self::KEY_BYTES, true)),
                'pin_digits' => strlen($pin),
                'version' => $record->version + 1,
                'set_by' => $by->id,
                'set_at' => now(),
            ])->save();

            SnapshotCache::bump($employee->tenant_id);
            $this->auditor->record('hr.employee.clock_pin_set', $employee, null, ['version' => $record->version]);

            return $record;
        });
    }

    public function clear(Employee $employee, User $by): void
    {
        Errors::transaction(function () use ($employee) {
            $record = ClockPin::query()->where('employee_id', $employee->id)->lockForUpdate()->first();

            if ($record === null) {
                return;
            }

            // Not a business record: the credential is removed, the clearing audited.
            $record->delete();
            SnapshotCache::bump($employee->tenant_id);
            $this->auditor->record('hr.employee.clock_pin_clear', $employee, ['pin_set' => true], ['pin_set' => false]);
        });
    }

    /** @return array{scheme: string, kid: string, salt: string, iterations: int, verifier: string} */
    public function material(ClockPin $record, DeviceSecret $secret): array
    {
        return [
            'scheme' => Pins::SCHEME,
            'kid' => $secret->kid,
            'salt' => $record->pin_salt,
            'iterations' => (int) $record->pin_iterations,
            'verifier' => DeviceSecrets::encode(DeviceSecrets::hmac($secret, self::message($record->employee_id, DeviceSecrets::decode($record->pin_key)))),
        ];
    }

    public static function message(string $employeeId, string $rawKey): string
    {
        return self::PREFIX.':v1:'.$employeeId.':'.$rawKey;
    }
}
