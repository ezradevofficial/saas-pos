<?php

namespace Modules\FuelStation\Tanks;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FuelStation\Approvals\TankVarianceType;
use Modules\FuelStation\Events\TankReconciled;
use Modules\FuelStation\Models\Delivery;
use Modules\FuelStation\Models\Dip;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Reconciliation;
use Modules\FuelStation\Models\Settings;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\FuelError;
use Modules\FuelStation\Support\Litres;
use Modules\FuelStation\Support\Numbers;
use Modules\Inventory\Contracts\StockGained;
use Modules\Inventory\Contracts\StockWrittenOff;

/**
 * FUEL-24, FUEL-26: a tank's dip against its book stock.
 *
 *   expected = baseline + deliveries received since the baseline − fuel sold since the baseline
 *   variance = dip − expected            (negative: a loss, positive: a gain)
 *
 * Sales and deliveries are counted once: each is marked with the
 * reconciliation that consumed it, so a sale that arrives late (offline) is
 * counted by the next reconciliation, never twice and never lost. The first
 * dip of a tank only sets its baseline. Within the tolerance the result is
 * `accepted` and posted to Inventory at once; beyond it, it waits for a
 * `fuel.tank_variance` approval (never by the person who took the dip) and
 * is posted on approval; a rejected one posts nothing. The tank's baseline
 * moves to the dip in every case: the dip is a fact. The caller holds the
 * tank's row lock.
 */
class Reconciliations
{
    public function __construct(
        private readonly Numbers $numbers,
        private readonly Auditor $auditor,
        private readonly WorkflowEngine $engine,
    ) {}

    public function reconcile(Dip $dip, Tank $tank, User $by): Reconciliation
    {
        $hasBaseline = $tank->baseline_litres !== null;
        $sales = $this->sum(FuelSale::query()->where('tank_id', $tank->id)->whereNull('reconciliation_id')->whereNull('voided_at')->where('sold_at', '<=', $dip->taken_at), 'litres');
        $deliveries = $this->sum(Delivery::query()->where('tank_id', $tank->id)->whereNull('reconciliation_id')->where('received_at', '<=', $dip->taken_at), 'received_litres');
        $dipLitres = Litres::of($dip->litres);
        $expected = $hasBaseline ? Litres::of($tank->baseline_litres)->plus($deliveries)->minus($sales) : $dipLitres;
        $variance = $hasBaseline ? $dipLitres->minus($expected) : Litres::zero();
        // FUEL-26: the allowance is cumulative over a day: variances already accepted without approval in the 24 hours before this dip use it up,
        // so splitting a loss over several small dips cannot keep each under the tolerance.
        $recent = Reconciliation::query()->join('fuel_dips', 'fuel_dips.id', '=', 'fuel_reconciliations.dip_id')->where('fuel_reconciliations.tank_id', $tank->id)
            ->where('fuel_reconciliations.status', Reconciliation::ACCEPTED)->where('fuel_dips.taken_at', '>=', $dip->taken_at->subDay())->where('fuel_dips.taken_at', '<=', $dip->taken_at)
            ->selectRaw('coalesce(sum(abs(fuel_reconciliations.variance_litres)), 0) as used, coalesce(sum(fuel_reconciliations.sales_litres), 0) as sold')->first();
        $tolerance = $this->tolerance($tank, $sales->plus(Litres::of((string) $recent->sold)));
        $status = ! $hasBaseline ? Reconciliation::BASELINE : ($variance->abs()->plus(Litres::of((string) $recent->used))->isLessThanOrEqualTo($tolerance) ? Reconciliation::ACCEPTED : Reconciliation::PENDING);

        $reconciliation = Reconciliation::create([
            'company_id' => $tank->company_id,
            'branch_id' => $tank->branch_id,
            'location_id' => $tank->location_id,
            'tank_id' => $tank->id,
            'dip_id' => $dip->id,
            'number' => $this->numbers->next(Numbers::RECONCILIATION, $tank->location_id),
            'status' => $status,
            'previous_litres' => $tank->baseline_litres,
            'baseline_at' => $tank->baseline_at,
            'deliveries_litres' => (string) $deliveries,
            'sales_litres' => (string) $sales,
            'expected_litres' => (string) $expected,
            'dip_litres' => (string) $dipLitres,
            'variance_litres' => (string) $variance,
            'tolerance_litres' => (string) $tolerance,
            'flags' => [],
        ]);

        // Consumed: counted by this reconciliation, never again.
        FuelSale::query()->where('tank_id', $tank->id)->whereNull('reconciliation_id')->where('sold_at', '<=', $dip->taken_at)->update(['reconciliation_id' => $reconciliation->id]);
        Delivery::query()->where('tank_id', $tank->id)->whereNull('reconciliation_id')->where('received_at', '<=', $dip->taken_at)->update(['reconciliation_id' => $reconciliation->id]);
        $tank->forceFill(['baseline_litres' => (string) $dipLitres, 'baseline_at' => $dip->taken_at])->save();

        $this->auditor->record('fuel.reconciliation.create', $reconciliation, null, [
            'number' => $reconciliation->number, 'tank_id' => $tank->id, 'status' => $status,
            'expected_litres' => (string) $expected, 'dip_litres' => (string) $dipLitres, 'variance_litres' => (string) $variance,
        ], ['user_id' => $by->id]);

        if ($status === Reconciliation::ACCEPTED) {
            $this->post($reconciliation, $tank, $dip, $by->id);
        } elseif ($status === Reconciliation::PENDING) {
            $this->engine->start(TankVarianceType::KEY, $reconciliation->id, $by);
        }

        return $reconciliation->refresh();
    }

    /** WF-10: the flow ended; an approved variance is posted, a rejected one is only recorded. */
    public function completed(string $reconciliationId, string $outcome, ?string $userId): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($reconciliationId, $outcome, $userId) {
            $reconciliation = Reconciliation::query()->whereKey($reconciliationId)->lockForUpdate()->first();

            if ($reconciliation === null || $reconciliation->status !== Reconciliation::PENDING) {
                return;
            }

            $approved = $outcome === 'approved';
            $reconciliation->forceFill(['status' => $approved ? Reconciliation::APPROVED : Reconciliation::REJECTED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();
            $this->auditor->record($approved ? 'fuel.reconciliation.approve' : 'fuel.reconciliation.reject', $reconciliation, ['status' => Reconciliation::PENDING], ['status' => $reconciliation->status], ['user_id' => $userId]);

            if ($approved) {
                $this->post($reconciliation, Tank::query()->findOrFail($reconciliation->tank_id), Dip::query()->findOrFail($reconciliation->dip_id), $userId);
            }
        });
    }

    /** WF-11, FUEL-26: the approval was cancelled: the reconciliation waits to be submitted again (nothing is posted). */
    public function cancelled(string $reconciliationId): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($reconciliationId) {
            $reconciliation = Reconciliation::query()->whereKey($reconciliationId)->lockForUpdate()->first();

            if ($reconciliation !== null && $reconciliation->status === Reconciliation::PENDING) {
                $reconciliation->forceFill(['status' => Reconciliation::CANCELLED])->save();
                $this->auditor->record('fuel.reconciliation.cancel', $reconciliation, ['status' => Reconciliation::PENDING], ['status' => Reconciliation::CANCELLED]);
            }
        });
    }

    /** FUEL-26: a cancelled reconciliation goes back for approval (a new flow). */
    public function resubmit(Reconciliation $reconciliation, User $by): Reconciliation
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($reconciliation, $by) {
            $reconciliation = Reconciliation::query()->whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();

            if ($reconciliation->status !== Reconciliation::CANCELLED) {
                throw FuelError::make(409, 'not_cancelled');
            }

            $reconciliation->forceFill(['status' => Reconciliation::PENDING])->save();
            $this->engine->start(TankVarianceType::KEY, $reconciliation->id, $by);
            $this->auditor->record('fuel.reconciliation.resubmit', $reconciliation, ['status' => Reconciliation::CANCELLED], ['status' => Reconciliation::PENDING], ['user_id' => $by->id]);

            return $reconciliation->refresh();
        });
    }

    /** The loss leaves Inventory through StockWrittenOff, the gain enters through StockGained (FUEL-30); with Inventory off nothing happens. */
    private function post(Reconciliation $reconciliation, Tank $tank, Dip $dip, ?string $userId): void
    {
        $variance = BigDecimal::of((string) $reconciliation->variance_litres);
        $product = Product::query()->findOrFail($tank->product_id);
        $at = $dip->taken_at->utc()->format('Y-m-d\TH:i:s.u\Z');
        $lines = [['line_id' => $reconciliation->id, 'item_id' => $product->item_id, 'qty' => (string) $variance->abs()]];

        if (! $variance->isZero()) {
            event($variance->isNegative()
                ? new StockWrittenOff($reconciliation->tenant_id, $reconciliation->id, $tank->company_id, $tank->location_id, $at, $reconciliation->number, $lines, $userId, 'fuel.tank_loss')
                : new StockGained($reconciliation->tenant_id, $reconciliation->id, $tank->company_id, $tank->location_id, $at, $reconciliation->number, $lines, $userId));
            $reconciliation->forceFill(['posted_at' => CarbonImmutable::now()])->save();
        }

        TankReconciled::dispatch($reconciliation->tenant_id, $reconciliation->id, $tank->id, $reconciliation->status, (string) $variance);
    }

    /** The tank's own litre tolerance, else the larger of the company's litres and its percent of the throughput (FUEL-26). */
    private function tolerance(Tank $tank, BigDecimal $throughput): BigDecimal
    {
        if ($tank->tolerance_litres !== null) {
            return Litres::of($tank->tolerance_litres);
        }

        $settings = Settings::for($tank->company_id);
        $percent = $throughput->multipliedBy(BigDecimal::of((string) $settings->loss_tolerance_percent))->dividedBy(100, 3, RoundingMode::HalfUp);

        return Litres::max(Litres::of($settings->loss_tolerance_litres), $percent);
    }

    private function sum($query, string $column): BigDecimal
    {
        return Litres::of((string) ($query->selectRaw("coalesce(sum({$column}), 0) as total")->value('total') ?? '0'));
    }
}
