<?php

namespace Modules\VanSales\Reconciliation;

use App\Core\Audit\Auditor;
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
use Modules\VanSales\Support\BaseAmounts;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Support\Qty;
use Modules\VanSales\Sync\DeviceUploads;
use Throwable;

/**
 * VAN-18 to VAN-23: the end-of-day count and its reconciliation.
 *
 * - submit(): the rep's count (idempotent by id) becomes a reconciliation:
 *   stock per item (`Equation::variance`) and cash and mobile money per
 *   currency (counted − expected). The load is what was sent from the
 *   warehouse, so a short delivery the rep reports shows up here, in front
 *   of an approver, and is never written off on the rep's word alone. What
 *   the rep says came back is held to what Inventory shows on the van
 *   (`returns_capped`); without Inventory, more than the day allows is
 *   flagged (`returns_exceed`). Nothing to decide (no variance, nothing
 *   damaged): `balanced`. Otherwise `pending` and the
 *   `van_sales.reconciliation` flow starts (WF-01); the rep who counted is
 *   the requester and, with the run's rep, never its approver (APR-07). The
 *   good stock brought back is requested as an Inventory transfer to the
 *   warehouse of the day's load (VAN-23), whatever the decision. The run
 *   closes only once the count is settled and that unload is received.
 * - completed(): the flow decided. Approved: the damaged quantity and the
 *   shortage (less what an earlier approval of this day already wrote off)
 *   are written off from the van by Inventory (VAN-22); rejected: the rep
 *   may count again (a new attempt).
 * - unloadShortfall(): the warehouse received less than the unload held: a
 *   count of its own goes through the same flow.
 */
class Counts
{
    public function __construct(
        private readonly StockLevels $levels,
        private readonly StockTransfers $transfers,
        private readonly WorkflowEngine $engine,
        private readonly BaseAmounts $base,
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

            if (in_array($latest?->status, ['balanced', 'approved'], true)) {
                throw Errors::make(409, 'run_closed', field: 'run_id');
            }

            if ($latest?->status === 'pending') {
                throw Errors::make(409, 'count_open', field: 'run_id');
            }

            $van = Van::query()->findOrFail($run->van_id);
            $this->adoptLoads($run);
            $company = Company::query()->findOrFail($run->company_id);
            $at = CarbonImmutable::parse($data['submitted_at'])->utc();

            [$stock, $flags] = $this->stock($run, $van, $data['lines'] ?? []);
            $tenders = $this->tenders($run, $data['tenders'] ?? []);
            $varianceLines = count(array_filter($stock, fn (array $l) => ! $l['variance']->isZero()));
            $damaged = count(array_filter($stock, fn (array $l) => $l['damaged']->isPositive()));
            $cashVariance = 0;

            foreach ($tenders as $row) {
                $cashVariance += abs($this->base->minor($company, $row['currency'], $row['variance'], $at) ?? 0);
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
                'kind' => 'count',
                'flags' => $flags,
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
                    'returned_qty' => (string) $line['returned'], 'returned_claimed_qty' => $line['claimed'] === null ? null : (string) $line['claimed'],
                    'damaged_qty' => (string) $line['damaged'], 'variance_qty' => (string) $line['variance'],
                ]);
            }

            foreach ($tenders as $row) {
                CountTender::create([
                    'count_id' => $count->id, 'kind' => $row['kind'], 'currency' => $row['currency'],
                    'expected_minor' => $row['expected'], 'counted_minor' => $row['counted'], 'variance_minor' => $row['variance'],
                ]);
            }

            $this->requestUnload($count, $van, $run, $stock, $by);
            $run->forceFill(['status' => 'counting'])->save();

            if ($needsDecision) {
                $this->engine->start(ReconciliationType::KEY, $count->id, $by);
            } else {
                $this->closeIfDone($run);
            }

            $this->auditor->record('van_sales.count.submit', $count, null, [
                'attempt' => $count->attempt, 'status' => $count->status, 'stock_variance_lines' => $varianceLines, 'flags' => $flags,
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

            $shortfall = $count->kind === 'unload_shortfall';
            $count->forceFill(['status' => 'approved', 'decided_by' => $userId, 'decided_at' => $now, 'written_off_at' => $shortfall ? null : $now])->save();
            $run = Run::query()->whereKey($count->run_id)->lockForUpdate()->firstOrFail();
            $van = Van::query()->findOrFail($count->van_id);
            $lines = [];

            if (! $shortfall) {
                $written = $this->writtenBefore($count);

                foreach (CountLine::query()->where('count_id', $count->id)->get() as $line) {
                    $gone = BigDecimal::of((string) $line->damaged_qty)->plus(BigDecimal::of((string) $line->variance_qty)->isPositive() ? (string) $line->variance_qty : '0')
                        ->minus($written[$line->item_id] ?? '0');

                    if ($gone->isPositive()) {
                        $lines[] = ['line_id' => $line->id, 'item_id' => $line->item_id, 'qty' => (string) Qty::show($gone)];
                    }
                }
            }

            $this->auditor->record('van_sales.count.approve', $count, ['status' => 'pending'], [
                'status' => 'approved', 'kind' => $count->kind, 'written_off_lines' => count($lines), 'cash_variance_base_minor' => (string) $count->cash_variance_base_minor,
            ], ['user_id' => $userId]);
            $this->closeIfDone($run);

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

    /**
     * VAN-23: the warehouse received less than the unload held. The difference
     * is a count of its own in the reconciliation flow; the run stays open to
     * decision until it is approved or turned down.
     *
     * @param  list<array{item_id: string, qty: string, qty_received: ?string}>  $lines  the transfer's lines after receipt
     */
    public function unloadShortfall(Count $unloaded, array $lines, User $by): ?Count
    {
        $short = [];

        foreach ($lines as $line) {
            $sent = BigDecimal::of($line['qty']);
            $got = BigDecimal::of($line['qty_received'] ?? $line['qty']);

            if ($got->isLessThan($sent)) {
                $short[] = ['item_id' => $line['item_id'], 'sent' => $sent, 'got' => $got];
            }
        }

        if ($short === []) {
            return null;
        }

        $latest = Count::query()->where('run_id', $unloaded->run_id)->max('attempt');
        $count = Count::create([
            'company_id' => $unloaded->company_id, 'branch_id' => $unloaded->branch_id, 'van_id' => $unloaded->van_id, 'run_id' => $unloaded->run_id,
            'attempt' => (int) $latest + 1, 'kind' => 'unload_shortfall', 'flags' => ['unload_shortfall'], 'status' => 'pending',
            'submitted_by' => $by->id, 'submitted_at' => CarbonImmutable::now(), 'stock_variance_lines' => count($short),
            'note' => $unloaded->id, 'content_digest' => hash('sha256', 'shortfall|'.$unloaded->id.'|'.$unloaded->unload_transfer_id),
        ]);

        foreach ($short as $row) {
            CountLine::create([
                'count_id' => $count->id, 'item_id' => $row['item_id'], 'load_qty' => (string) Qty::of($row['sent']), 'sold_qty' => '0',
                'returned_qty' => (string) Qty::of($row['got']), 'damaged_qty' => '0', 'variance_qty' => (string) Qty::of($row['sent']->minus($row['got'])),
            ]);
        }

        $this->engine->start(ReconciliationType::KEY, $count->id, $by);
        $this->auditor->record('van_sales.count.unload_shortfall', $count, null, ['unload_of' => $unloaded->id, 'lines' => count($short)], ['user_id' => $by->id]);

        return $count;
    }

    /**
     * The run closes when its count is settled (balanced or approved), nothing waits
     * for a decision and its unload, if one was asked, has been received (or called off).
     */
    public function closeIfDone(Run $run): void
    {
        $counts = Count::query()->where('run_id', $run->id)->get();

        if ($counts->contains('status', 'pending')) {
            return;
        }

        $main = $counts->where('kind', 'count')->sortByDesc('attempt')->first();

        if ($main === null || ! in_array($main->status, ['balanced', 'approved'], true)) {
            return;
        }

        if ($main->unload_transfer_id !== null && $this->transfers->active()
            && ! in_array($this->transfers->statuses([$main->unload_transfer_id])[$main->unload_transfer_id] ?? null, ['received', 'cancelled', 'rejected'], true)) {
            return;
        }

        $run->forceFill(['status' => 'closed', 'closed_at' => CarbonImmutable::now()])->save();
    }

    /** What earlier approved counts of this day already wrote off, per item. @return array<string, BigDecimal> */
    private function writtenBefore(Count $count): array
    {
        $written = [];
        $earlier = Count::query()->where('run_id', $count->run_id)->where('kind', 'count')->where('attempt', '<', $count->attempt)->whereNotNull('written_off_at')->pluck('id');

        foreach (CountLine::query()->whereIn('count_id', $earlier)->get() as $line) {
            $gone = BigDecimal::of((string) $line->damaged_qty)->plus(BigDecimal::of((string) $line->variance_qty)->isPositive() ? (string) $line->variance_qty : '0');
            $written[$line->item_id] = ($written[$line->item_id] ?? BigDecimal::zero())->plus($gone);
        }

        return $written;
    }

    /** What earlier counts of this day brought back to the warehouse, per item: the unloads already received. @return array<string, BigDecimal> */
    private function returnedBefore(Run $run): array
    {
        $back = [];

        $earlier = Count::query()->where('run_id', $run->id)->where('kind', 'count')->whereIn('status', ['balanced', 'approved', 'rejected'])->whereNotNull('unload_transfer_id')->get();
        $received = $this->transfers->statuses($earlier->pluck('unload_transfer_id')->all());

        foreach ($earlier as $count) {
            if (($received[$count->unload_transfer_id] ?? null) !== 'received') {
                continue;
            }

            foreach ($this->transfers->state($count->unload_transfer_id)['lines'] ?? [] as $line) {
                $back[$line['item_id']] = ($back[$line['item_id']] ?? BigDecimal::zero())->plus($line['qty_received'] ?? $line['qty']);
            }
        }

        return $back;
    }

    /** Confirmed sheets made ahead of the day join the run being counted. */
    private function adoptLoads(Run $run): void
    {
        Load::query()->where('van_id', $run->van_id)->where('state', 'confirmed')->whereNull('run_id')->get()->each(fn (Load $load) => $load->forceFill(['run_id' => $run->id])->save());
    }

    /**
     * @param  list<array{item_id: string, returned_qty: string, damaged_qty: string}>  $given
     * @return array{0: array<string, array{load: BigDecimal, sold: BigDecimal, returned: BigDecimal, claimed: ?BigDecimal, damaged: BigDecimal, variance: BigDecimal}>, 1: list<string>}
     */
    private function stock(Run $run, Van $van, array $given): array
    {
        // What was sent from the warehouse, not what the rep says arrived: a short delivery is a variance for an approver.
        $loaded = LoadLine::query()->join('vs_loads', 'vs_loads.id', '=', 'vs_load_lines.load_id')
            ->where('vs_loads.run_id', $run->id)->where('vs_loads.state', 'confirmed')
            ->groupBy('vs_load_lines.item_id')->selectRaw('vs_load_lines.item_id, sum(vs_load_lines.qty) as qty')->pluck('qty', 'item_id')->all();
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

        $items = array_values(array_unique([...array_keys($loaded), ...array_keys($back), ...array_keys($sold), ...array_keys($counted)]));
        // VAN-19: the rep cannot bring back more than the van holds (Inventory); null while Inventory is off.
        $onHand = $items === [] ? [] : $this->levels->onHand($van->location_id, $items);
        $before = $this->returnedBefore($run);
        $rows = [];
        $flags = [];

        foreach ($items as $itemId) {
            $load = Qty::of((string) ($loaded[$itemId] ?? '0'))->plus(Qty::of((string) ($back[$itemId] ?? '0')));
            $net = Qty::of((string) ($sold[$itemId] ?? '0'));
            $entered = $counted[$itemId]['returned'] ?? Qty::zero();
            $damaged = $counted[$itemId]['damaged'] ?? Qty::zero();
            $claimed = null;
            $returned = $entered;

            if ($onHand !== null) {
                $held = Qty::of((string) ($onHand[$itemId] ?? '0'));

                if ($entered->isGreaterThan($held)) {
                    $claimed = $entered;
                    $returned = $held->isNegative() ? Qty::zero() : $held;
                    $flags[] = 'returns_capped';
                }
            }

            $returned = Qty::of($returned->plus($before[$itemId] ?? '0'));

            if ($onHand === null && $returned->isGreaterThan($load->minus($net)->minus($damaged))) {
                $flags[] = 'returns_exceed';
            }

            $rows[$itemId] = ['load' => $load, 'sold' => $net, 'returned' => $returned, 'claimed' => $claimed === null ? null : Qty::of($claimed->plus($before[$itemId] ?? '0')),
                'damaged' => $damaged, 'variance' => Equation::variance($load, $net, $returned, $damaged)];
        }

        return [$rows, array_values(array_unique($flags))];
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
        $before = $this->returnedBefore($run);
        $returned = [];

        foreach ($stock as $itemId => $line) {
            $now = $line['returned']->minus($before[$itemId] ?? '0');

            if ($now->isPositive()) {
                $returned[$itemId] = $now;
            }
        }

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
