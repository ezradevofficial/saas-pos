<?php

namespace Modules\VanSales\Reconciliation;

use App\Core\Audit\Auditor;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\StockLevels;
use Modules\Inventory\Contracts\StockTransfers;
use Modules\Inventory\Contracts\StockWrittenOff;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\CountLine;
use Modules\VanSales\Models\CountTender;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Models\LoadLine;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\StockLine;
use Modules\VanSales\Models\Tender;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Support\Qty;
use Modules\VanSales\Sync\DeviceUploads;
use Throwable;

/**
 * VAN-18 to VAN-23: the end-of-day count and its reconciliation.
 *
 * - submit(): the rep's count (idempotent by id) becomes a reconciliation:
 *   stock per item (`Equation::variance`) and cash and mobile money per
 *   currency (counted − expected). Nothing to decide (no variance, nothing
 *   damaged): `balanced`, the run closes. Otherwise `pending` and the
 *   `van_sales.reconciliation` flow starts (WF-01); the rep who counted is
 *   the requester and never its approver (APR-07). The good stock the rep
 *   brings back is requested as an Inventory transfer to the warehouse of
 *   the day's load (VAN-23), whatever the decision.
 * - completed(): the flow decided. Approved: the damaged quantity and the
 *   shortage are written off from the van by Inventory (VAN-22) and the run
 *   closes; rejected: the rep may count again (a new attempt).
 */
class Counts
{
    public function __construct(
        private readonly StockLevels $levels,
        private readonly StockTransfers $transfers,
        private readonly WorkflowEngine $engine,
        private readonly ExchangeRates $rates,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{id: string, submitted_at: string, note?: ?string, lines?: list<array{item_id: string, returned_qty: string, damaged_qty: string}>, tenders?: list<array{kind: string, currency: string, counted_minor: string}>}  $data
     */
    public function submit(Run $run, array $data, User $by): Count
    {
        $digest = DeviceUploads::digest($data);

        return Errors::transaction(function () use ($run, $data, $by, $digest) {
            $run = Run::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $existing = Count::query()->find($data['id']);

            if ($existing !== null) {
                if ($existing->run_id !== $run->id || $existing->content_digest !== $digest) {
                    throw Errors::make(422, 'payload_mismatch', field: 'id');
                }

                return $existing;
            }

            $latest = Count::query()->where('run_id', $run->id)->orderByDesc('attempt')->first();

            if ($run->status === 'closed' || in_array($latest?->status, ['balanced', 'approved'], true)) {
                throw Errors::make(409, 'run_closed', field: 'run_id');
            }

            if ($latest?->status === 'pending') {
                throw Errors::make(409, 'count_open', field: 'run_id');
            }

            $van = Van::query()->findOrFail($run->van_id);
            $this->adoptLoads($run);
            $company = Company::query()->findOrFail($run->company_id);
            $at = CarbonImmutable::parse($data['submitted_at'])->utc();

            $stock = $this->stock($run, $data['lines'] ?? []);
            $tenders = $this->tenders($run, $data['tenders'] ?? []);
            $varianceLines = count(array_filter($stock, fn (array $l) => ! $l['variance']->isZero()));
            $damaged = count(array_filter($stock, fn (array $l) => $l['damaged']->isPositive()));
            $cashVariance = 0;

            foreach ($tenders as $row) {
                $cashVariance += abs($this->toBase($company, $row['currency'], $row['variance'], $at));
            }

            $tenderVariance = count(array_filter($tenders, fn (array $r) => $r['variance'] !== 0)) > 0;
            // Damage is written off, and a write-off is a decision (VAN-21): it waits like a variance.
            $needsDecision = $varianceLines > 0 || $tenderVariance || $damaged > 0;

            $count = Count::create([
                'id' => $data['id'],
                'company_id' => $run->company_id,
                'branch_id' => $run->branch_id,
                'van_id' => $run->van_id,
                'run_id' => $run->id,
                'attempt' => ($latest?->attempt ?? 0) + 1,
                'status' => $needsDecision ? 'pending' : 'balanced',
                'submitted_by' => $by->id,
                'submitted_at' => $at,
                'decided_at' => $needsDecision ? null : CarbonImmutable::now(),
                'note' => $data['note'] ?? null,
                'stock_variance_lines' => $varianceLines,
                'cash_variance_base_minor' => $cashVariance,
                'content_digest' => $digest,
            ]);

            foreach ($stock as $itemId => $line) {
                CountLine::create([
                    'count_id' => $count->id, 'item_id' => $itemId, 'load_qty' => (string) $line['load'], 'sold_qty' => (string) $line['sold'],
                    'returned_qty' => (string) $line['returned'], 'damaged_qty' => (string) $line['damaged'], 'variance_qty' => (string) $line['variance'],
                ]);
            }

            foreach ($tenders as $row) {
                CountTender::create([
                    'count_id' => $count->id, 'kind' => $row['kind'], 'currency' => $row['currency'],
                    'expected_minor' => $row['expected'], 'counted_minor' => $row['counted'], 'variance_minor' => $row['variance'],
                ]);
            }

            $run->forceFill($needsDecision ? ['status' => 'counting'] : ['status' => 'closed', 'closed_at' => CarbonImmutable::now()])->save();
            $this->requestUnload($count, $van, $run, $stock, $by);

            if ($needsDecision) {
                $this->engine->start(ReconciliationType::KEY, $count->id, $by);
            }

            $this->auditor->record('van_sales.count.submit', $count, null, [
                'attempt' => $count->attempt, 'status' => $count->status, 'stock_variance_lines' => $varianceLines,
                'cash_variance_base_minor' => (string) $cashVariance, 'run_id' => $run->id,
            ], ['user_id' => $by->id]);

            return $count->refresh();
        });
    }

    /** WF-10: the flow decided the count. */
    public function completed(string $countId, string $outcome, ?string $userId): void
    {
        $event = Errors::transaction(function () use ($countId, $outcome, $userId) {
            $count = Count::query()->whereKey($countId)->lockForUpdate()->first();

            if ($count === null || $count->status !== 'pending') {
                return null;
            }

            $now = CarbonImmutable::now();

            if ($outcome !== 'approved') {
                $count->forceFill(['status' => 'rejected', 'decided_by' => $userId, 'decided_at' => $now])->save();
                $this->auditor->record('van_sales.count.reject', $count, ['status' => 'pending'], ['status' => 'rejected'], ['user_id' => $userId]);

                return null;
            }

            $count->forceFill(['status' => 'approved', 'decided_by' => $userId, 'decided_at' => $now, 'written_off_at' => $now])->save();
            Run::query()->whereKey($count->run_id)->update(['status' => 'closed', 'closed_at' => $now]);
            $van = Van::query()->findOrFail($count->van_id);
            $lines = [];

            foreach (CountLine::query()->where('count_id', $count->id)->get() as $line) {
                $gone = BigDecimal::of((string) $line->damaged_qty)->plus(BigDecimal::of((string) $line->variance_qty)->isPositive() ? (string) $line->variance_qty : '0');

                if ($gone->isPositive()) {
                    $lines[] = ['line_id' => $line->id, 'item_id' => $line->item_id, 'qty' => (string) Qty::show($gone)];
                }
            }

            $this->auditor->record('van_sales.count.approve', $count, ['status' => 'pending'], [
                'status' => 'approved', 'written_off_lines' => count($lines), 'cash_variance_base_minor' => (string) $count->cash_variance_base_minor,
            ], ['user_id' => $userId]);

            return $lines === [] ? null : new StockWrittenOff(
                $this->tenants->require(), $count->id, $count->company_id, $van->location_id, $now->utc()->format('Y-m-d\TH:i:s.u\Z'),
                $van->name, $lines, $userId, 'van_sales.write_off',
            );
        });

        // After commit (the event waits for it): Inventory takes the stock out, when it is active.
        if ($event !== null) {
            event($event);
        }
    }

    /** WF-11: the count's flow was cancelled: the rep counts again. */
    public function cancelled(string $countId): void
    {
        Errors::transaction(function () use ($countId) {
            $count = Count::query()->whereKey($countId)->lockForUpdate()->first();

            if ($count !== null && $count->status === 'pending') {
                $count->forceFill(['status' => 'rejected', 'decided_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    /** Confirmed sheets made ahead of the day join the run being counted. */
    private function adoptLoads(Run $run): void
    {
        Load::query()->where('van_id', $run->van_id)->where('state', 'confirmed')->whereNull('run_id')->get()->each(fn (Load $load) => $load->forceFill(['run_id' => $run->id])->save());
    }

    /**
     * @param  list<array{item_id: string, returned_qty: string, damaged_qty: string}>  $given
     * @return array<string, array{load: BigDecimal, sold: BigDecimal, returned: BigDecimal, damaged: BigDecimal, variance: BigDecimal}>
     */
    private function stock(Run $run, array $given): array
    {
        $loaded = LoadLine::query()->join('vs_loads', 'vs_loads.id', '=', 'vs_load_lines.load_id')
            ->where('vs_loads.run_id', $run->id)->where('vs_loads.state', 'confirmed')
            ->groupBy('vs_load_lines.item_id')->selectRaw('vs_load_lines.item_id, sum(vs_load_lines.qty_confirmed) as qty')->pluck('qty', 'item_id')->all();
        $back = StockLine::query()->where('run_id', $run->id)->where('kind', 'customer_return')->groupBy('item_id')->selectRaw('item_id, sum(qty) as qty')->pluck('qty', 'item_id')->all();
        $sold = StockLine::query()->where('run_id', $run->id)->where('kind', 'sold')->groupBy('item_id')->selectRaw('item_id, sum(qty) as qty')->pluck('qty', 'item_id')->all();
        $counted = [];

        foreach ($given as $index => $line) {
            if (! Item::query()->whereKey($line['item_id'])->exists()) {
                throw Errors::make(422, 'item_unknown', field: "lines.{$index}.item_id");
            }

            $counted[$line['item_id']] = [
                'returned' => ($counted[$line['item_id']]['returned'] ?? Qty::zero())->plus(Qty::of((string) $line['returned_qty'])),
                'damaged' => ($counted[$line['item_id']]['damaged'] ?? Qty::zero())->plus(Qty::of((string) $line['damaged_qty'])),
            ];
        }

        $rows = [];

        foreach (array_unique([...array_keys($loaded), ...array_keys($back), ...array_keys($sold), ...array_keys($counted)]) as $itemId) {
            $load = Qty::of((string) ($loaded[$itemId] ?? '0'))->plus(Qty::of((string) ($back[$itemId] ?? '0')));
            $net = Qty::of((string) ($sold[$itemId] ?? '0'));
            $returned = $counted[$itemId]['returned'] ?? Qty::zero();
            $damaged = $counted[$itemId]['damaged'] ?? Qty::zero();
            $rows[$itemId] = ['load' => $load, 'sold' => $net, 'returned' => $returned, 'damaged' => $damaged, 'variance' => Equation::variance($load, $net, $returned, $damaged)];
        }

        return $rows;
    }

    /**
     * @param  list<array{kind: string, currency: string, counted_minor: string}>  $given
     * @return list<array{kind: string, currency: string, expected: int, counted: int, variance: int}>
     */
    private function tenders(Run $run, array $given): array
    {
        $expected = Tender::query()->where('run_id', $run->id)->groupBy('kind', 'currency')->selectRaw('kind, currency, sum(amount_minor) as amount')->get()
            ->mapWithKeys(fn ($row) => [$row->kind.'|'.$row->currency => (int) $row->amount])->all();
        $counted = [];

        foreach ($given as $row) {
            $key = $row['kind'].'|'.$row['currency'];
            $counted[$key] = ($counted[$key] ?? 0) + (int) $row['counted_minor'];
        }

        $rows = [];

        foreach (array_unique([...array_keys($expected), ...array_keys($counted)]) as $key) {
            [$kind, $currency] = explode('|', $key);
            $rows[] = ['kind' => $kind, 'currency' => $currency, 'expected' => $expected[$key] ?? 0, 'counted' => $counted[$key] ?? 0,
                'variance' => Equation::tenderVariance($expected[$key] ?? 0, $counted[$key] ?? 0)];
        }

        usort($rows, fn (array $a, array $b) => [$a['currency'], $a['kind']] <=> [$b['currency'], $b['kind']]);

        return $rows;
    }

    /** A variance in the company's base currency at the rate of the day; none stored counts as 0 here (the per-currency figures stay). */
    private function toBase(Company $company, string $currency, int $minor, CarbonImmutable $at): int
    {
        if ($minor === 0 || $currency === $company->base_currency) {
            return $minor;
        }

        try {
            $snapshot = FxSnapshot::fromRate($this->rates->stored($company, $currency, (string) $company->base_currency, $at));

            return (int) $snapshot->convert(Money::ofMinor((string) $minor, $currency))->minor();
        } catch (RateUnavailable) {
            return 0;
        }
    }

    /**
     * VAN-23: the good stock brought back, no more than the van holds, goes
     * to the warehouse the day's load came from. Never fails the count.
     *
     * @param  array<string, array{returned: BigDecimal}>  $stock
     */
    private function requestUnload(Count $count, Van $van, Run $run, array $stock, User $by): void
    {
        if (! $this->transfers->active()) {
            return;
        }

        $from = Load::query()->where('van_id', $van->id)->where('run_id', $run->id)->where('state', 'confirmed')->orderByDesc('confirmed_at')->value('from_location_id');
        $returned = array_filter(array_map(fn (array $l) => $l['returned'], $stock), fn (BigDecimal $qty) => $qty->isPositive());

        if ($from === null || $returned === []) {
            return;
        }

        $onHand = $this->levels->onHand($van->location_id, array_keys($returned)) ?? [];
        $lines = [];

        foreach ($returned as $itemId => $qty) {
            $take = Qty::min($qty, Qty::of((string) ($onHand[$itemId] ?? '0')));

            if ($take->isPositive()) {
                $lines[] = ['item_id' => $itemId, 'qty' => (string) Qty::show($take)];
            }
        }

        if ($lines === []) {
            return;
        }

        try {
            // A savepoint: a refused transfer must not poison the count's transaction.
            $transfer = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->transfers->request($by, $van->location_id, $from, $lines, $van->name));
            $count->forceFill(['unload_transfer_id' => $transfer['id'] ?? null])->save();
        } catch (Throwable $e) {
            Log::warning('van_sales: the unload transfer was not made', ['count' => $count->id, 'error' => $e->getMessage()]);
        }
    }
}
