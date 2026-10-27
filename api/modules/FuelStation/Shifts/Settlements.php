<?php

namespace Modules\FuelStation\Shifts;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\FuelStation\Approvals\ShiftVarianceType;
use Modules\FuelStation\Events\SettlementReopened;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\SaleTender;
use Modules\FuelStation\Models\Settings;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\SettlementTender;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Prices\Prices;
use Modules\FuelStation\Support\Litres;
use Modules\FuelStation\Support\Numbers;

/**
 * FUEL-11 to FUEL-15: the money side of a shift, computed on the server.
 *
 * Per shift nozzle (once it has a closing reading): litres sold by the meter
 * (Meters), litres and value of the live fuel sales recorded on it, the
 * litres no sale was rung for, and the expected value
 *
 *   expected = recorded sales value + unrecorded litres × the price in force at close.
 *
 * Per attendant (their nozzles): collected = the cash they declared + what
 * the POS recorded for every other tender; variance = collected − expected.
 * A variance within the company's tolerances is `auto_accepted`; otherwise it
 * is `pending_approval` and a `fuel.shift_variance` flow decides it (never
 * the attendant or whoever opened or closed the shift, ShiftVarianceType).
 * A sale, void or refund that arrives after the settlement was decided
 * recomputes it; changed figures reopen it for approval (FUEL-15). Everything
 * runs under the shift's row lock; the caller owns the transaction.
 */
class Settlements
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly WorkflowEngine $engine,
        private readonly Numbers $numbers,
        private readonly Prices $prices,
    ) {}

    /**
     * Compute and store the figures of $shift's settlements.
     *
     * @param  array<string, array<string, int|string>>|null  $declared  attendant id => tender kind => minor units, as handed in at close (null: keep what is stored)
     */
    public function settle(Shift $shift, ?array $declared, ?User $closer, string $cause): void
    {
        $shift = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
        $settings = Settings::for($shift->company_id);
        $closed = $shift->status === Shift::CLOSED;
        $rows = ShiftNozzle::query()->where('shift_id', $shift->id)->orderBy('id')->get();
        $nozzles = Nozzle::query()->whereKey($rows->pluck('nozzle_id'))->get()->keyBy('id');
        $tanks = Tank::query()->whereKey($nozzles->pluck('tank_id')->unique())->get()->keyBy('id');
        $station = DB::table('fuel_stations')->where('id', $shift->station_id)->first(['id', 'currency']);
        $litreTolerance = Litres::of($settings->litre_tolerance);

        foreach ($rows as $row) {
            $recorded = FuelSale::query()->where('shift_nozzle_id', $row->id)->whereNull('voided_at')
                ->selectRaw('coalesce(sum(litres), 0) as litres, coalesce(sum(total_minor), 0) as total')->first();
            $row->recorded_litres = (string) Litres::of((string) $recorded->litres);
            $row->recorded_minor = (int) $recorded->total;

            if ($row->closing_litres !== null) {
                $meter = Meters::litres($row->opening_litres, $row->closing_litres, $row->test_litres, $row->reset_litres);
                $unrecorded = Meters::unrecorded($meter, Litres::of($row->recorded_litres));
                $productId = $tanks->get($nozzles->get($row->nozzle_id)->tank_id)->product_id;
                $price = $this->prices->at($shift->station_id, $productId, $shift->closed_at ?? CarbonImmutable::now());
                $flags = [];
                $diff = $meter->minus(Litres::of($row->recorded_litres));

                if ($diff->abs()->isGreaterThan($litreTolerance)) {
                    $flags[] = ['code' => $diff->isNegative() ? 'sold_over_meter' : 'litres_differ', 'litres' => (string) $diff];
                }

                if ($unrecorded->isPositive() && $price === null) {
                    $flags[] = ['code' => 'price_missing'];
                }

                // Flags the server noticed when the shift was opened stay; the figures' flags are recomputed.
                $kept = array_values(array_filter($row->flags ?? [], fn ($f) => in_array($f['code'] ?? '', ['meter_gap', 'attendant_not_permitted'], true)));
                $row->forceFill([
                    'meter_litres' => (string) $meter,
                    'unrecorded_litres' => (string) $unrecorded,
                    'price_minor' => $price?->price_minor,
                    'expected_minor' => $row->recorded_minor + ($price === null ? 0 : Litres::value($price->price_minor, $unrecorded)),
                    'flags' => [...$kept, ...$flags],
                ]);
            }

            $row->save();
        }

        $unassigned = FuelSale::query()->where('shift_id', $shift->id)->whereNull('shift_nozzle_id')->whereNull('voided_at')->count();
        $shiftFlags = array_values(array_filter($shift->flags ?? [], fn ($f) => ($f['code'] ?? '') !== 'unassigned_sales'));

        if ($unassigned > 0) {
            $shiftFlags[] = ['code' => 'unassigned_sales', 'count' => $unassigned];
        }

        if ($shiftFlags !== ($shift->flags ?? [])) {
            $shift->forceFill(['flags' => $shiftFlags])->save();
        }

        foreach ($rows->groupBy('attendant_id') as $attendantId => $mine) {
            $this->settleAttendant($shift, (string) $attendantId, $mine, $declared[(string) $attendantId] ?? null, $declared !== null, $settings, $station->currency, $closed, $closer, $cause);
        }
    }

    /**
     * @param  Collection<int, ShiftNozzle>  $rows
     * @param  array<string, int|string>|null  $declared
     */
    private function settleAttendant(Shift $shift, string $attendantId, $rows, ?array $declared, bool $declaring, Settings $settings, string $currency, bool $closed, ?User $closer, string $cause): void
    {
        $settlement = Settlement::query()->where('shift_id', $shift->id)->where('attendant_id', $attendantId)->lockForUpdate()->first()
            ?? Settlement::create([
                'company_id' => $shift->company_id, 'branch_id' => $shift->branch_id, 'location_id' => $shift->location_id,
                'shift_id' => $shift->id, 'attendant_id' => $attendantId, 'status' => Settlement::OPEN, 'currency' => $currency,
            ]);

        $before = $this->figures($settlement);
        $recorded = SaleTender::query()->where('shift_id', $shift->id)->where('attendant_id', $attendantId)->whereNull('voided_at')
            ->selectRaw('kind, sum(amount_minor) as total')->groupBy('kind')->pluck('total', 'kind');
        $flags = array_values(array_filter($settlement->flags ?? [], fn ($f) => ! in_array($f['code'] ?? '', ['tender_declared_differs'], true)));
        $collected = 0;

        foreach (Tenders::KINDS as $kind) {
            $tender = SettlementTender::query()->where('settlement_id', $settlement->id)->where('kind', $kind)->first()
                ?? new SettlementTender(['settlement_id' => $settlement->id, 'kind' => $kind]);
            $kindRecorded = (int) ($recorded[$kind] ?? 0);

            if ($declaring) {
                $tender->declared_minor = isset($declared[$kind]) ? (int) $declared[$kind] : null;
            }

            $tender->recorded_minor = $kindRecorded;
            $tender->collected_minor = $kind === 'cash' ? (int) ($tender->declared_minor ?? 0) : $kindRecorded;

            if ($kind !== 'cash' && $tender->declared_minor !== null && $tender->declared_minor !== $kindRecorded) {
                $flags[] = ['code' => 'tender_declared_differs', 'kind' => $kind, 'declared_minor' => (string) $tender->declared_minor, 'recorded_minor' => (string) $kindRecorded];
            }

            $tender->save();
            $collected += $tender->collected_minor;
        }

        $expected = (int) $rows->sum('expected_minor');
        $meterLitres = $rows->reduce(fn (BigDecimal $sum, ShiftNozzle $r) => $sum->plus(Litres::of($r->meter_litres ?? '0')), Litres::zero());
        $recordedLitres = $rows->reduce(fn (BigDecimal $sum, ShiftNozzle $r) => $sum->plus(Litres::of($r->recorded_litres)), Litres::zero());
        $settlement->fill([
            'expected_minor' => $expected,
            'collected_minor' => $collected,
            'variance_minor' => $collected - $expected,
            'meter_litres' => (string) $meterLitres,
            'recorded_litres' => (string) $recordedLitres,
            'litres_variance' => (string) $meterLitres->minus($recordedLitres),
            'flags' => $flags,
        ]);

        if (! $closed) {
            $settlement->save();

            return;
        }

        $after = $this->figures($settlement);
        $needs = abs($settlement->variance_minor) > (int) $settings->cash_variance_tolerance_minor
            || BigDecimal::of((string) $settlement->litres_variance)->abs()->isGreaterThan(Litres::of($settings->litre_tolerance));

        if ($settlement->status === Settlement::OPEN) {
            $settlement->number = $this->numbers->next(Numbers::SETTLEMENT, $shift->location_id);
            $settlement->status = $needs ? Settlement::PENDING : Settlement::AUTO;
            $settlement->save();
            $this->auditor->record('fuel.settlement.create', $settlement, null, ['number' => $settlement->number, 'status' => $settlement->status, ...$after], ['user_id' => $closer?->id]);

            if ($needs) {
                $this->engine->start(ShiftVarianceType::KEY, $settlement->id, $closer);
            }

            return;
        }

        if ($settlement->status === Settlement::PENDING) {
            if ($before !== $after) {
                $settlement->save();
                $this->auditor->record('fuel.settlement.update', $settlement, $before, $after, ['cause' => $cause]);
            }

            return;
        }

        if ($before === $after) {
            $settlement->save();

            return;
        }

        // FUEL-15: a decided settlement whose figures changed goes back for approval.
        $previous = $settlement->status;
        $settlement->version = $settlement->version + 1;
        $settlement->reopened_at = CarbonImmutable::now();
        $settlement->status = $needs ? Settlement::PENDING : Settlement::AUTO;
        $settlement->decided_by = null;
        $settlement->decided_at = null;
        $settlement->flags = [...($settlement->flags ?? []), ['code' => 'reopened', 'version' => $settlement->version, 'cause' => $cause]];
        $settlement->save();
        $this->auditor->record('fuel.settlement.reopen', $settlement, ['status' => $previous, ...$before], ['status' => $settlement->status, 'version' => $settlement->version, ...$after], ['cause' => $cause]);

        if ($needs) {
            $this->engine->start(ShiftVarianceType::KEY, $settlement->id, null);
        }

        SettlementReopened::dispatch($settlement->tenant_id, $settlement->id, $shift->id, $settlement->version, $cause);
    }

    /** WF-10: the flow ended: approved or rejected, by a person other than the attendant and the shift's openers and closers. */
    public function completed(string $settlementId, string $outcome, ?string $userId): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($settlementId, $outcome, $userId) {
            $settlement = Settlement::query()->whereKey($settlementId)->lockForUpdate()->first();

            if ($settlement === null || $settlement->status !== Settlement::PENDING) {
                return;
            }

            $status = $outcome === 'approved' ? Settlement::APPROVED : Settlement::REJECTED;
            $settlement->forceFill(['status' => $status, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();
            $this->auditor->record($status === Settlement::APPROVED ? 'fuel.settlement.approve' : 'fuel.settlement.reject', $settlement, ['status' => Settlement::PENDING], ['status' => $status, 'variance_minor' => (string) $settlement->variance_minor], ['user_id' => $userId]);
        });
    }

    /** @return array<string, string> */
    private function figures(Settlement $settlement): array
    {
        return [
            'expected_minor' => (string) $settlement->expected_minor,
            'collected_minor' => (string) $settlement->collected_minor,
            'variance_minor' => (string) $settlement->variance_minor,
            'meter_litres' => Litres::show($settlement->meter_litres),
            'recorded_litres' => Litres::show($settlement->recorded_litres),
        ];
    }
}
