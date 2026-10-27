<?php

namespace Modules\FuelStation\Shifts;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\FuelStation\Approvals\ShiftVarianceType;
use Modules\FuelStation\Events\SettlementReopened;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\SaleTender;
use Modules\FuelStation\Models\Settings;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\SettlementTender;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\FuelError;
use Modules\FuelStation\Support\Litres;
use Modules\FuelStation\Support\Numbers;

/**
 * FUEL-11 to FUEL-15, FUEL-28: the money side of a shift, computed on the server.
 *
 * Per shift nozzle (once it has a closing reading): litres sold by the meter
 * (Meters), the live fuel sales recorded on it **valued at the server's price
 * in force at each sale** (never the till's price), the litres nobody rang up
 * (the meter's difference, plus the `meter_gap` between the last shift's close
 * and this opening reading) valued at the price in force at close:
 *
 *   expected = recorded litres × server price + unrecorded litres × the price at close.
 *
 * Per attendant (their nozzles): collected = the cash they declared + what the
 * POS recorded for every other tender (a fuel voucher only for what it really
 * paid, Vouchers); variance = collected − expected. A variance within the
 * company's tolerances is `auto_accepted`, unless something forces approval: a
 * `meter_gap`, pump tests above the test tolerance, a missing price, or any sale
 * on the shift carrying a review flag (voucher invalid, vehicle, outside the
 * shift, price differs, discount, unverified actor, ...). Then it is
 * `pending_approval` and a `fuel.shift_variance` flow decides it (never the
 * attendant or whoever opened or closed the shift, ShiftVarianceType). A sale,
 * void or refund that arrives after a decision recomputes it; changed figures
 * reopen it and it always goes back to `pending_approval` (FUEL-15). A cancelled
 * approval leaves it `cancelled`, to be submitted again. Everything runs under
 * the shift's row lock; the caller owns the transaction.
 */
class Settlements
{
    /** Sale flags that do not need a second pair of eyes. */
    public const BENIGN_SALE_FLAGS = ['litres_rounded', 'refunded', 'received_after_close'];

    /** Settlement flags that force approval whatever the amounts. */
    public const FORCING = ['meter_gap', 'test_above_tolerance', 'flagged_sales', 'price_missing'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly WorkflowEngine $engine,
        private readonly Numbers $numbers,
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
        $testTolerance = Litres::of($settings->test_tolerance_litres);
        /** @var array<string, Collection<int, Price>> $priceBook */
        $priceBook = [];
        $priceAt = function (string $productId, CarbonInterface $at) use (&$priceBook, $station): ?Price {
            $priceBook[$productId] ??= Price::query()->where('station_id', $station->id)->where('product_id', $productId)->whereNull('archived_at')->orderByDesc('effective_from')->orderByDesc('id')->get();

            return $priceBook[$productId]->first(fn (Price $p) => $p->effective_from->lessThanOrEqualTo($at));
        };
        $forced = [];

        foreach ($rows as $row) {
            $productId = $tanks->get($nozzles->get($row->nozzle_id)->tank_id)->product_id;
            $sales = FuelSale::query()->where('shift_nozzle_id', $row->id)->whereNull('voided_at')->get(['litres', 'total_minor', 'sold_at']);
            $value = 0;
            $missing = false;

            // FUEL-28: every recorded sale is valued at the server's price in force when it was made, not at the till's.
            foreach ($sales as $sale) {
                $price = $priceAt($productId, $sale->sold_at);
                $price === null ? $missing = true : null;
                $value += $price === null ? (int) $sale->total_minor : Litres::value($price->price_minor, $sale->litres);
            }

            $row->recorded_litres = (string) $sales->reduce(fn (BigDecimal $sum, FuelSale $s) => $sum->plus(Litres::of($s->litres)), Litres::zero());
            $row->recorded_minor = $value;

            if ($row->closing_litres !== null) {
                $meter = Meters::litres($row->opening_litres, $row->closing_litres, $row->test_litres, $row->reset_litres);
                $gap = Litres::of($row->gap_litres);
                $unrecorded = Meters::unrecorded($meter, Litres::of($row->recorded_litres))->plus($gap);
                $closePrice = $priceAt($productId, $shift->closed_at ?? CarbonImmutable::now());
                $flags = [];
                $diff = $meter->minus(Litres::of($row->recorded_litres));

                if ($diff->abs()->isGreaterThan($litreTolerance)) {
                    $flags[] = ['code' => $diff->isNegative() ? 'sold_over_meter' : 'litres_differ', 'litres' => (string) $diff];
                }

                if (($unrecorded->isPositive() && $closePrice === null) || $missing) {
                    $flags[] = ['code' => 'price_missing'];
                    $forced['price_missing'] = true;
                }

                // FUEL-11: litres that moved the meter between shifts are charged to this one, and always reviewed.
                if ($gap->isPositive()) {
                    $forced['meter_gap'] = true;
                }

                // FUEL-11: pump tests above the allowance are fuel taken out of the tank on paper.
                if (Litres::of($row->test_litres)->isGreaterThan($testTolerance)) {
                    $flags[] = ['code' => 'test_above_tolerance', 'litres' => (string) Litres::of($row->test_litres)];
                    $forced['test_above_tolerance'] = true;
                }

                $kept = array_values(array_filter($row->flags ?? [], fn ($f) => in_array($f['code'] ?? '', ['meter_gap', 'attendant_not_permitted', 'attendant_unverified'], true)));
                $row->forceFill([
                    'meter_litres' => (string) $meter,
                    'unrecorded_litres' => (string) $unrecorded,
                    'price_minor' => $closePrice?->price_minor,
                    'expected_minor' => $value + ($closePrice === null ? 0 : Litres::value($closePrice->price_minor, $unrecorded)),
                    'flags' => [...$kept, ...$flags],
                ]);
            }

            $row->save();
        }

        $unassigned = FuelSale::query()->where('shift_id', $shift->id)->whereNull('shift_nozzle_id')->whereNull('voided_at')->count();
        $shiftFlags = array_values(array_filter($shift->flags ?? [], fn ($f) => ($f['code'] ?? '') !== 'unassigned_sales'));

        if ($unassigned > 0) {
            $shiftFlags[] = ['code' => 'unassigned_sales', 'count' => $unassigned];
            $forced['flagged_sales'] = true;
        }

        if ($shiftFlags !== ($shift->flags ?? [])) {
            $shift->forceFill(['flags' => $shiftFlags])->save();
        }

        // FUEL-18: any sale of the shift carrying a review flag (the server's or the POS's) sends every settlement of the shift to approval.
        $flagged = FuelSale::query()->where('shift_id', $shift->id)->whereNull('voided_at')->whereRaw("flags <> '[]'::jsonb")->pluck('flags')
            ->flatten(1)->pluck('code')->diff(self::BENIGN_SALE_FLAGS);

        if ($flagged->isNotEmpty()) {
            $forced['flagged_sales'] = true;
        }

        foreach ($rows->groupBy('attendant_id') as $attendantId => $mine) {
            $mineForced = $forced;

            // The attendant's own nozzles carry the gap and test reasons; the rest are the shift's.
            if (! $mine->contains(fn (ShiftNozzle $r) => Litres::of($r->gap_litres)->isPositive())) {
                unset($mineForced['meter_gap']);
            }

            if (! $mine->contains(fn (ShiftNozzle $r) => in_array('test_above_tolerance', array_column($r->flags ?? [], 'code'), true))) {
                unset($mineForced['test_above_tolerance']);
            }

            $this->settleAttendant($shift, (string) $attendantId, $mine, $declared[(string) $attendantId] ?? null, $declared !== null, $settings, $station->currency, $closed, $closer, $cause, array_keys($mineForced));
        }
    }

    /**
     * @param  Collection<int, ShiftNozzle>  $rows
     * @param  array<string, int|string>|null  $declared
     * @param  list<string>  $forced  reasons that force approval whatever the amounts
     */
    private function settleAttendant(Shift $shift, string $attendantId, $rows, ?array $declared, bool $declaring, Settings $settings, string $currency, bool $closed, ?User $closer, string $cause, array $forced): void
    {
        $settlement = Settlement::query()->where('shift_id', $shift->id)->where('attendant_id', $attendantId)->lockForUpdate()->first()
            ?? Settlement::create([
                'company_id' => $shift->company_id, 'branch_id' => $shift->branch_id, 'location_id' => $shift->location_id,
                'shift_id' => $shift->id, 'attendant_id' => $attendantId, 'status' => Settlement::OPEN, 'currency' => $currency,
            ]);

        $recorded = SaleTender::query()->where('shift_id', $shift->id)->where('attendant_id', $attendantId)->whereNull('voided_at')
            ->selectRaw('kind, sum(amount_minor) as total')->groupBy('kind')->pluck('total', 'kind');
        $flags = array_values(array_filter($settlement->flags ?? [], fn ($f) => ($f['code'] ?? '') === 'reopened'));
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

        foreach ($forced as $reason) {
            $flags[] = ['code' => $reason];
        }

        $before = $this->figures($settlement);
        $expected = (int) $rows->sum('expected_minor');
        $meterLitres = $rows->reduce(fn (BigDecimal $sum, ShiftNozzle $r) => $sum->plus(Litres::of($r->meter_litres ?? '0')), Litres::zero());
        $gapLitres = $rows->reduce(fn (BigDecimal $sum, ShiftNozzle $r) => $sum->plus(Litres::of($r->gap_litres)), Litres::zero());
        $recordedLitres = $rows->reduce(fn (BigDecimal $sum, ShiftNozzle $r) => $sum->plus(Litres::of($r->recorded_litres)), Litres::zero());
        $settlement->fill([
            'expected_minor' => $expected,
            'collected_minor' => $collected,
            'variance_minor' => $collected - $expected,
            'meter_litres' => (string) $meterLitres,
            'recorded_litres' => (string) $recordedLitres,
            // The gap between shifts is litres nobody rang up either.
            'litres_variance' => (string) $meterLitres->plus($gapLitres)->minus($recordedLitres),
            'flags' => $flags,
        ]);

        if (! $closed) {
            $settlement->save();

            return;
        }

        $after = $this->figures($settlement);
        $needs = $forced !== []
            || abs($settlement->variance_minor) > (int) $settings->cash_variance_tolerance_minor
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

        if (in_array($settlement->status, [Settlement::PENDING, Settlement::CANCELLED], true)) {
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

        // FUEL-15: a decided settlement whose figures changed goes back for approval, always: a late change is never accepted by itself.
        $previous = $settlement->status;
        $settlement->version = $settlement->version + 1;
        $settlement->reopened_at = CarbonImmutable::now();
        $settlement->status = Settlement::PENDING;
        $settlement->decided_by = null;
        $settlement->decided_at = null;
        $settlement->flags = [...($settlement->flags ?? []), ['code' => 'reopened', 'version' => $settlement->version, 'cause' => $cause]];
        $settlement->save();
        $this->auditor->record('fuel.settlement.reopen', $settlement, ['status' => $previous, ...$before], ['status' => $settlement->status, 'version' => $settlement->version, ...$after], ['cause' => $cause]);
        $this->engine->start(ShiftVarianceType::KEY, $settlement->id, null);
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

    /** WF-11, FUEL-14: the approval was cancelled: the settlement waits to be submitted again, it is not pending for ever. */
    public function cancelled(string $settlementId): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($settlementId) {
            $settlement = Settlement::query()->whereKey($settlementId)->lockForUpdate()->first();

            if ($settlement !== null && $settlement->status === Settlement::PENDING) {
                $settlement->forceFill(['status' => Settlement::CANCELLED])->save();
                $this->auditor->record('fuel.settlement.cancel', $settlement, ['status' => Settlement::PENDING], ['status' => Settlement::CANCELLED]);
            }
        });
    }

    /** FUEL-14: a cancelled settlement goes back for approval (a new flow); the submitter is whoever resubmits. */
    public function resubmit(Settlement $settlement, User $by): Settlement
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($settlement, $by) {
            $settlement = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($settlement->status !== Settlement::CANCELLED) {
                throw FuelError::make(409, 'not_cancelled');
            }

            $settlement->forceFill(['status' => Settlement::PENDING])->save();
            $this->engine->start(ShiftVarianceType::KEY, $settlement->id, $by);
            $this->auditor->record('fuel.settlement.resubmit', $settlement, ['status' => Settlement::CANCELLED], ['status' => Settlement::PENDING], ['user_id' => $by->id]);

            return $settlement->refresh();
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
            'forced' => implode(',', array_values(array_intersect(array_column($settlement->flags ?? [], 'code'), self::FORCING))),
        ];
    }
}
