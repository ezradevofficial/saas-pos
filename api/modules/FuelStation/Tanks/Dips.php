<?php

namespace Modules\FuelStation\Tanks;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FuelStation\Models\Dip;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\FuelError;

/**
 * FUEL-23: a dip of a tank at a time, in cm; the server converts it to
 * litres with the chart in force (FUEL-06) and never takes litres from a
 * device. Dips are append-only and idempotent by id (a resend answers the
 * dip already stored). A `routine`, `shift_open` or `shift_close` dip
 * reconciles the tank (FUEL-24) under a row lock on the tank; one that does
 * not follow the tank's last reconciled dip in time is refused
 * `dip_out_of_order`; delivery dips are made by Deliveries and reconcile
 * nothing. The caller owns the transaction and the permission checks.
 */
class Dips
{
    /** A dip may carry a clock this far ahead of the server. */
    private const CLOCK_TOLERANCE_MINUTES = 5;

    public function __construct(private readonly Strapping $strapping, private readonly Reconciliations $reconciliations) {}

    /**
     * @param  array{id?: ?string, kind: string, dip_cm: string|int, water_cm?: string|int|null, taken_at: string}  $data
     */
    public function record(Tank $tank, array $data, User $by, string $source, ?string $shiftId = null): Dip
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tank, $data, $by, $source, $shiftId) {
            $id = $data['id'] ?? (string) Str::uuid7();
            $existing = Dip::query()->find($id);

            if ($existing !== null) {
                if ($existing->tank_id !== $tank->id) {
                    throw FuelError::make(409, 'id_conflict', field: 'id');
                }

                return $existing;
            }

            $tank = Tank::query()->whereKey($tank->id)->lockForUpdate()->firstOrFail();

            if ($tank->archived_at !== null) {
                throw FuelError::make(422, 'tank_unknown', field: 'tank_id');
            }

            $takenAt = CarbonImmutable::parse($data['taken_at'])->utc();

            if ($takenAt->greaterThan(CarbonImmutable::now()->addMinutes(self::CLOCK_TOLERANCE_MINUTES))) {
                throw FuelError::make(422, 'dip_in_future', field: 'taken_at');
            }

            $reconciling = in_array($data['kind'], Dip::RECONCILING, true);

            if ($reconciling && $tank->baseline_at !== null && $takenAt->lessThan($tank->baseline_at)) {
                throw FuelError::make(422, 'dip_out_of_order', field: 'taken_at');
            }

            $cm = BigDecimal::of((string) $data['dip_cm'])->toScale(2, RoundingMode::HalfUp);
            [$chart, $litres] = $this->strapping->convert($tank, $cm, $takenAt);

            // FUEL-23: water lies under the fuel; the dip's fuel is the level less the water's litres by the same chart.
            $water = isset($data['water_cm']) ? BigDecimal::of((string) $data['water_cm'])->toScale(2, RoundingMode::HalfUp) : null;

            if ($water !== null) {
                if ($water->isGreaterThan($cm)) {
                    throw FuelError::make(422, 'water_above_dip', field: 'water_cm');
                }

                $litres = $litres->minus($this->strapping->convert($tank, $water, $takenAt, 'water_cm')[1]);
            }

            $dip = Dip::create([
                'id' => $id,
                'company_id' => $tank->company_id,
                'branch_id' => $tank->branch_id,
                'location_id' => $tank->location_id,
                'tank_id' => $tank->id,
                'shift_id' => $shiftId,
                'chart_id' => $chart->id,
                'kind' => $data['kind'],
                'source' => $source,
                'dip_cm' => (string) $cm,
                'water_cm' => $water === null ? null : (string) $water,
                'litres' => (string) $litres,
                'taken_at' => $takenAt,
                'received_at' => CarbonImmutable::now(),
                'taken_by' => $by->id,
            ]);

            if ($reconciling) {
                $this->reconciliations->reconcile($dip, $tank, $by);
            }

            return $dip->refresh();
        });
    }
}
