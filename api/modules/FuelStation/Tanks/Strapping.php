<?php

namespace Modules\FuelStation\Tanks;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\FuelStation\Models\StrappingChart;
use Modules\FuelStation\Models\StrappingPoint;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\FuelError;
use Modules\FuelStation\Support\Litres;

/**
 * FUEL-06: a tank's calibration (strapping) charts and the conversion of a
 * dip in cm to litres. Charts are immutable and effective-dated; the chart
 * in force at the dip time is used and stored with the dip. Conversion is
 * linear interpolation between the two nearest points, exact (brick/math),
 * rounded half up to 3 decimals; outside the chart it is refused.
 */
class Strapping
{
    public function __construct(private readonly Auditor $auditor) {}

    /** The chart of $tank in force at $at, or 422 `chart_missing`. */
    public function chartAt(Tank $tank, CarbonInterface $at): StrappingChart
    {
        return StrappingChart::query()->where('tank_id', $tank->id)->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')->orderByDesc('id')->first()
            ?? throw FuelError::make(422, 'chart_missing', field: 'tank_id');
    }

    /** @return array{0: StrappingChart, 1: BigDecimal} the chart used and the litres for the dip */
    public function convert(Tank $tank, BigDecimal|string $dipCm, CarbonInterface $at, string $field = 'dip_cm'): array
    {
        $chart = $this->chartAt($tank, $at);
        $points = StrappingPoint::query()->where('chart_id', $chart->id)->orderBy('dip_cm')->get(['dip_cm', 'litres'])
            ->map(fn ($p) => [BigDecimal::of((string) $p->dip_cm), BigDecimal::of((string) $p->litres)])->all();

        return [$chart, self::interpolate($points, BigDecimal::of((string) $dipCm), $field)];
    }

    /**
     * @param  list<array{0: BigDecimal, 1: BigDecimal}>  $points  (dip cm, litres), sorted by dip, strictly rising
     */
    public static function interpolate(array $points, BigDecimal $dip, string $field = 'dip_cm'): BigDecimal
    {
        if ($points === [] || $dip->isLessThan($points[0][0]) || $dip->isGreaterThan($points[count($points) - 1][0])) {
            throw FuelError::make(422, 'dip_out_of_range', field: $field);
        }

        foreach ($points as $i => [$cm, $litres]) {
            if ($dip->isEqualTo($cm)) {
                return Litres::of($litres);
            }

            if ($dip->isLessThan($cm)) {
                [$cm0, $litres0] = $points[$i - 1];

                return Litres::of($litres0->plus($dip->minus($cm0)->multipliedBy($litres->minus($litres0))->dividedBy($cm->minus($cm0), 9, RoundingMode::HalfUp)));
            }
        }

        throw FuelError::make(422, 'dip_out_of_range', field: $field);
    }

    /**
     * A new chart for $tank from $points (`[{dip_cm, litres}]`): at least two,
     * strictly rising in both columns, never above the tank's capacity.
     *
     * @param  list<array{dip_cm: string|int, litres: string|int}>  $points
     */
    public function create(Tank $tank, array $points, CarbonInterface $effectiveFrom, ?string $note, User $by): StrappingChart
    {
        $sorted = collect($points)->map(fn ($p) => [BigDecimal::of((string) $p['dip_cm'])->toScale(2, RoundingMode::HalfUp), Litres::of($p['litres'])])
            ->sortBy(fn ($p) => (string) $p[0], SORT_NUMERIC)->values()->all();

        foreach ($sorted as $i => [$cm, $litres]) {
            if ($litres->isGreaterThan(BigDecimal::of((string) $tank->capacity_litres))) {
                throw FuelError::make(422, 'chart_above_capacity', field: "points.{$i}.litres");
            }

            if ($i > 0 && ($cm->isLessThanOrEqualTo($sorted[$i - 1][0]) || $litres->isLessThanOrEqualTo($sorted[$i - 1][1]))) {
                throw FuelError::make(422, 'chart_not_rising', field: "points.{$i}");
            }
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tank, $sorted, $effectiveFrom, $note, $by) {
            $chart = StrappingChart::create([
                'company_id' => $tank->company_id, 'tank_id' => $tank->id, 'effective_from' => $effectiveFrom, 'note' => $note, 'created_by' => $by->id,
            ]);

            foreach ($sorted as [$cm, $litres]) {
                StrappingPoint::create(['chart_id' => $chart->id, 'dip_cm' => (string) $cm, 'litres' => (string) $litres]);
            }

            $this->auditor->record('fuel.strapping_chart.points', $chart, null, ['tank_id' => $tank->id, 'points' => count($sorted), 'effective_from' => $effectiveFrom->toIso8601String()]);

            return $chart->refresh();
        });
    }
}
