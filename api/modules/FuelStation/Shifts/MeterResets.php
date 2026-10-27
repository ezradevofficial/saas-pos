<?php

namespace Modules\FuelStation\Shifts;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FuelStation\Models\MeterReset;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Support\DeviceActors;
use Modules\FuelStation\Support\FuelError;
use Modules\FuelStation\Support\Litres;

/**
 * FUEL-17: meter resets. When a nozzle's meter is replaced or rolls over, a
 * supervisor asks for a reset (the reading before it and the reading after),
 * naming the open shift the nozzle is on, if any. A different person with
 * `fuel.meter.approve_reset` approves it (never the requester); only then
 * does the nozzle's baseline move to the reading after, and the litres
 * through the reset (`before − after`) count for the shift (Meters). A
 * rejected request changes nothing. Both decisions are audited.
 */
class MeterResets
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array{id?: ?string, shift_id?: ?string, reading_before: string|int, reading_after: string|int, reason: string, requested_at?: ?string}  $data
     */
    public function request(Nozzle $nozzle, array $data, User $by, bool $actorVerified = false, ?string $deviceId = null): MeterReset
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($nozzle, $data, $by, $actorVerified, $deviceId) {
            $id = $data['id'] ?? (string) Str::uuid7();
            $existing = MeterReset::query()->find($id);

            if ($existing !== null) {
                if ($existing->nozzle_id !== $nozzle->id) {
                    throw FuelError::make(409, 'id_conflict', field: 'id');
                }

                return $existing;
            }

            $nozzle = Nozzle::query()->whereKey($nozzle->id)->lockForUpdate()->firstOrFail();
            $before = Litres::of($data['reading_before']);
            $after = Litres::of($data['reading_after']);

            if ($after->isGreaterThan($before)) {
                throw FuelError::make(422, 'reset_not_lower', field: 'reading_after');
            }

            if ($nozzle->last_reading_litres !== null && $before->isLessThan(Litres::of($nozzle->last_reading_litres))) {
                throw FuelError::make(422, 'meter_decreased', field: 'reading_before');
            }

            $open = ShiftNozzle::query()->where('nozzle_id', $nozzle->id)->where('is_open', true)->first();
            $shiftId = $data['shift_id'] ?? null;

            if ($shiftId !== null) {
                if ($open === null || $open->shift_id !== $shiftId) {
                    throw FuelError::make(422, 'reset_shift_invalid', field: 'shift_id');
                }
            } elseif ($open !== null) {
                throw FuelError::make(422, 'nozzle_in_use', field: 'nozzle_id');
            }

            if (MeterReset::query()->where('nozzle_id', $nozzle->id)->where('status', MeterReset::PENDING)->exists()) {
                throw FuelError::make(422, 'reset_pending', field: 'nozzle_id');
            }

            $reset = MeterReset::create([
                'id' => $id, 'company_id' => $nozzle->company_id, 'branch_id' => $nozzle->branch_id, 'location_id' => $nozzle->location_id,
                'nozzle_id' => $nozzle->id, 'shift_id' => $shiftId, 'reading_before' => (string) $before, 'reading_after' => (string) $after, 'device_id' => $deviceId, 'nozzle_reading' => $nozzle->last_reading_litres,
                'reason' => $data['reason'], 'status' => MeterReset::PENDING, 'requested_by' => $by->id,
                'requested_at' => CarbonImmutable::parse($data['requested_at'] ?? now())->utc(), 'actor_verified' => $actorVerified,
            ]);

            return $reset->refresh();
        });
    }

    public function approve(MeterReset $reset, User $by): MeterReset
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($reset, $by) {
            $reset = $this->pending($reset, $by);
            $nozzle = Nozzle::query()->whereKey($reset->nozzle_id)->lockForUpdate()->firstOrFail();
            $this->assertCurrent($reset, $nozzle);
            $reset->forceFill(['status' => MeterReset::APPROVED, 'decided_by' => $by->id, 'decided_at' => CarbonImmutable::now()])->save();
            $nozzle->forceFill(['last_reading_litres' => $reset->reading_after, 'last_reading_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('fuel.meter_reset.approve', $reset, ['status' => MeterReset::PENDING], ['status' => MeterReset::APPROVED, 'reading_before' => (string) $reset->reading_before, 'reading_after' => (string) $reset->reading_after]);

            return $reset;
        });
    }

    public function reject(MeterReset $reset, User $by): MeterReset
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($reset, $by) {
            $reset = $this->pending($reset, $by);
            $reset->forceFill(['status' => MeterReset::REJECTED, 'decided_by' => $by->id, 'decided_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('fuel.meter_reset.reject', $reset, ['status' => MeterReset::PENDING], ['status' => MeterReset::REJECTED]);

            return $reset;
        });
    }

    /**
     * FUEL-17: an approval applies to the meter as it was asked about: the nozzle's last reading and the open shift it is on (or none) must
     * still be the ones of the request, else the request is stale and a new one is made.
     */
    private function assertCurrent(MeterReset $reset, Nozzle $nozzle): void
    {
        $open = ShiftNozzle::query()->where('nozzle_id', $nozzle->id)->where('is_open', true)->value('shift_id');
        $same = $reset->nozzle_reading === null
            ? $nozzle->last_reading_litres === null
            : $nozzle->last_reading_litres !== null && Litres::of($nozzle->last_reading_litres)->isEqualTo(Litres::of($reset->nozzle_reading));

        if (! $same || $open !== $reset->shift_id) {
            throw FuelError::make(409, 'reset_stale');
        }
    }

    /** The reset under a lock, still pending, and not decided by its requester. */
    private function pending(MeterReset $reset, User $by): MeterReset
    {
        $reset = MeterReset::query()->whereKey($reset->id)->lockForUpdate()->firstOrFail();

        if ($reset->status !== MeterReset::PENDING) {
            throw FuelError::make(409, 'reset_decided');
        }

        if ($reset->requested_by === $by->id) {
            throw FuelError::make(403, 'own_reset');
        }

        // The till could not prove who asked (AUTH-07): anyone signed in on it around then may have been the one.
        if (! $reset->actor_verified && $reset->device_id !== null) {
            $from = $reset->shift_id === null ? $reset->requested_at : (Shift::query()->whereKey($reset->shift_id)->value('opened_at') ?? $reset->requested_at);

            if (in_array($by->id, DeviceActors::signedIn($reset->device_id, CarbonImmutable::parse($from), $reset->requested_at), true)) {
                throw FuelError::make(403, 'own_reset');
            }
        }

        return $reset;
    }
}
