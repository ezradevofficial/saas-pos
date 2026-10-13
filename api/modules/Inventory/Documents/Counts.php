<?php

namespace Modules\Inventory\Documents;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Events\StockCountPosted;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\BatchBalance;
use Modules\Inventory\Models\CountLine;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockInput;
use Modules\Inventory\Stock\StockLedger;
use Modules\Inventory\Stock\Units;
use Modules\Inventory\Stock\Valuer;

/**
 * INV-09: stock counts.
 *
 * - start(): a full count (every stock item with a balance or a reorder
 *   rule at the location) or a cycle count (chosen items, or a category's),
 *   one line per item (per batch with stock for batch items, plus what is
 *   unbatched), expected quantities snapshot now: the location is frozen
 *   for those items until the count is submitted or cancelled. An item
 *   already in an open count there is refused (`count_in_progress`).
 * - enter(): counted quantities (base unit, its precision).
 * - submit(): every line counted; variances (counted − (expected at the
 *   freeze + what moved there since)) valued at the company's cost.
 *   No variance: posted at once. Otherwise pending, with the
 *   `inventory.count` flow (variance approval).
 * - completed(): approved: the variances posted (`count_gain`,
 *   `count_loss`, never refused: the count is what is there) and
 *   StockCountPosted raised; rejected: `rejected`.
 * - cancel(): a counting or pending count.
 */
class Counts
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly WorkflowEngine $engine,
        private readonly DocumentNumbers $numbers,
        private readonly Places $places,
        private readonly Valuer $valuer,
    ) {}

    /** @param array{location_id: string, kind: string, item_ids?: list<string>, category_id?: ?string, note?: ?string} $data */
    public function start(array $data, User $by): StockCount
    {
        $locationId = $data['location_id'];
        $place = $this->places->of($locationId) ?? throw StockLedger::error(422, 'location_unknown', field: 'location_id');

        if ($place['archived']) {
            throw StockLedger::error(422, 'location_archived', field: 'location_id');
        }

        return StockInput::transaction(function () use ($data, $by, $place, $locationId) {
            // M1: one count starts at a time per location, and postings there
            // (which take this lock shared) wait for the snapshot.
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::lockKey($locationId)]);
            $itemIds = $this->itemsToCount($data, $locationId);

            if ($itemIds === []) {
                throw StockLedger::error(422, 'count_no_items', field: $data['kind'] === StockCount::FULL ? 'location_id' : 'item_ids');
            }

            $busy = CountLine::query()->join('inventory_counts', 'inventory_counts.id', '=', 'inventory_count_lines.count_id')
                ->whereIn('inventory_counts.status', [StockCount::COUNTING, StockCount::PENDING])
                ->where('inventory_counts.location_id', $locationId)
                ->whereIn('inventory_count_lines.item_id', $itemIds)->exists();

            if ($busy) {
                throw StockLedger::error(422, 'count_in_progress', field: 'location_id');
            }

            $count = StockCount::create([
                'number' => $this->numbers->next(CountType::KEY, $place['company_id'], $place['branch_id'], $locationId),
                'company_id' => $place['company_id'],
                'branch_id' => $place['branch_id'],
                'location_id' => $locationId,
                'kind' => $data['kind'],
                'status' => StockCount::COUNTING,
                'note' => $data['note'] ?? null,
                'requested_by' => $by->id,
                'frozen_at' => CarbonImmutable::now(),
            ]);

            foreach ($this->snapshot($itemIds, $locationId) as [$itemId, $batchId, $expected]) {
                CountLine::create(['count_id' => $count->id, 'item_id' => $itemId, 'batch_id' => $batchId, 'expected_qty' => (string) $expected]);
            }

            return $count->refresh();
        });
    }

    /** @param list<array{id: string, counted_qty: ?string}> $lines */
    public function enter(StockCount $count, array $lines): StockCount
    {
        return StockInput::transaction(function () use ($count, $lines) {
            $count = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($count, [StockCount::COUNTING]);
            $known = $count->lines()->with('item.baseUom')->get()->keyBy('id');

            foreach (array_values($lines) as $index => $row) {
                $line = $known[$row['id']] ?? throw StockLedger::error(422, 'line_unknown', field: "lines.{$index}.id");

                if ($row['counted_qty'] === null) {
                    $line->forceFill(['counted_qty' => null])->save();

                    continue;
                }

                try {
                    $qty = BigDecimal::of((string) $row['counted_qty']);
                } catch (MathException) {
                    throw StockLedger::error(422, 'qty_invalid', field: "lines.{$index}.counted_qty");
                }

                if ($qty->isNegative() || $qty->strippedOfTrailingZeros()->getScale() > Units::decimals($line->item?->baseUom?->kind)) {
                    throw StockLedger::error(422, 'qty_precision', field: "lines.{$index}.counted_qty");
                }

                $line->forceFill(['counted_qty' => (string) Qty::of($qty)])->save();
            }

            return $count->refresh();
        });
    }

    public function submit(StockCount $count, User $by): StockCount
    {
        $posted = StockInput::transaction(function () use ($count, $by) {
            $count = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($count, [StockCount::COUNTING]);
            $lines = $count->lines()->get();

            if ($lines->contains(fn (CountLine $line) => $line->counted_qty === null)) {
                throw StockLedger::error(422, 'count_incomplete');
            }

            $gain = BigInteger::zero();
            $loss = BigInteger::zero();

            foreach ($lines as $line) {
                $variance = $this->variance($count, $line);
                $value = $this->valuer->value($count->company_id, $line->item_id, $variance->abs());

                if ($variance->isNegative()) {
                    $loss = $loss->plus($value);
                } else {
                    $gain = $gain->plus($value);
                }

                $line->forceFill(['variance_qty' => (string) $variance, 'variance_value_minor' => (string) ($variance->isNegative() ? $value->negated() : $value)])->save();
            }

            $now = CarbonImmutable::now();
            $none = $lines->every(fn (CountLine $line) => BigDecimal::of((string) $line->variance_qty)->isZero());
            $count->fill([
                'status' => $none ? StockCount::POSTED : StockCount::PENDING,
                'submitted_by' => $by->id,
                'submitted_at' => $now,
                'posted_at' => $none ? $now : null,
                'gain_minor' => (string) $gain,
                'loss_minor' => (string) $loss,
                'currency' => $this->valuer->currency($count->company_id),
            ])->save();

            if (! $none) {
                $this->engine->start(CountType::KEY, $count->id, $by);
            }

            return $none ? $count->refresh() : null;
        });

        if ($posted !== null) {
            StockCountPosted::dispatch($posted->tenant_id, $posted->id, $posted->company_id, $posted->location_id, (string) $posted->currency, '0', '0');

            return $posted;
        }

        return $count->refresh();
    }

    /** WF-10: an approved variance is posted; a rejected one ends the count. */
    public function completed(string $countId, string $outcome, ?string $userId): void
    {
        $posted = StockInput::transaction(function () use ($countId, $outcome, $userId) {
            $count = StockCount::query()->whereKey($countId)->lockForUpdate()->first();

            if ($count === null || $count->status !== StockCount::PENDING) {
                return null;
            }

            $now = CarbonImmutable::now();

            if ($outcome !== 'approved') {
                $count->fill(['status' => StockCount::REJECTED, 'decided_by' => $userId, 'decided_at' => $now])->save();

                return null;
            }

            $count->fill(['status' => StockCount::POSTED, 'decided_by' => $userId, 'decided_at' => $now, 'posted_at' => $now])->save();
            $posting = [];

            foreach ($count->lines()->get() as $line) {
                // H2: what moved since the freeze is already in the balance.
                $variance = $this->variance($count, $line);
                $line->forceFill(['variance_qty' => (string) $variance])->save();

                if (! $variance->isZero()) {
                    $posting[] = new PostingLine($line->item_id, $count->location_id, $variance->isPositive() ? Movement::COUNT_GAIN : Movement::COUNT_LOSS, $variance,
                        sourceLineId: $line->id, batchId: $line->batch_id);
                }
            }

            $movements = $this->ledger->post(new Posting($count->company_id, 'inventory.count', $count->id, $now, $userId, $posting, manual: false));
            $gain = BigInteger::zero();
            $loss = BigInteger::zero();

            foreach ($movements as $movement) {
                $value = BigInteger::of((string) $movement->value_minor);

                if ($value->isNegative()) {
                    $loss = $loss->plus($value->abs());
                } else {
                    $gain = $gain->plus($value);
                }

                CountLine::query()->whereKey($movement->source_line_id)->update(['variance_value_minor' => (string) $value]);
            }

            $count->forceFill(['gain_minor' => (string) $gain, 'loss_minor' => (string) $loss])->save();

            return $count->refresh();
        });

        if ($posted !== null) {
            StockCountPosted::dispatch($posted->tenant_id, $posted->id, $posted->company_id, $posted->location_id, (string) $posted->currency, (string) $posted->gain_minor, (string) $posted->loss_minor);
        }
    }

    /** WF-11: the count's flow was cancelled. */
    public function cancelled(string $countId): void
    {
        StockInput::transaction(function () use ($countId) {
            $count = StockCount::query()->whereKey($countId)->lockForUpdate()->first();

            if ($count !== null && $count->status === StockCount::PENDING) {
                $count->fill(['status' => StockCount::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(StockCount $count, User $by, string $reason): StockCount
    {
        return StockInput::transaction(function () use ($count, $by, $reason) {
            $count = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($count, [StockCount::COUNTING, StockCount::PENDING]);

            if ($count->status === StockCount::PENDING) {
                $workflow = $this->engine->current(CountType::KEY, $count->id);

                if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                    $this->engine->cancel($workflow, null, $reason);
                }
            }

            $count->fill(['status' => StockCount::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $count->refresh();
        });
    }

    /** The advisory lock of counts at a location (StockLedger takes it shared). */
    public static function lockKey(string $locationId): string
    {
        return 'inventory:count-location:'.$locationId;
    }

    /**
     * H2: counted − (expected at the freeze + what moved that item, and
     * batch, at the location since the freeze, the count's own posting
     * left out): sales and receipts during the count are already in the
     * balance, so they are never counted twice.
     */
    private function variance(StockCount $count, CountLine $line): BigDecimal
    {
        $since = Movement::query()
            ->where('location_id', $count->location_id)
            ->where('item_id', $line->item_id)
            ->where(fn ($q) => $line->batch_id === null ? $q->whereNull('batch_id') : $q->where('batch_id', $line->batch_id))
            ->where('occurred_at', '>=', $count->frozen_at->format('Y-m-d H:i:s.uP'))
            ->where(fn ($q) => $q->where('source_type', '<>', 'inventory.count')->orWhere('source_id', '<>', $count->id))
            ->sum('qty');

        return Qty::of(BigDecimal::of((string) $line->counted_qty)->minus((string) $line->expected_qty)->minus((string) ($since ?? '0')));
    }

    /** @return list<string> the stock items the count covers */
    private function itemsToCount(array $data, string $locationId): array
    {
        $stock = Item::query()->where('type', 'stock')->whereNull('archived_at');

        if ($data['kind'] === StockCount::FULL) {
            $ids = Balance::query()->where('location_id', $locationId)->pluck('item_id')
                ->merge(ReorderRule::query()->whereNull('archived_at')->where('location_id', $locationId)->pluck('item_id'))
                ->unique()->values()->all();

            return $ids === [] ? [] : $stock->whereKey($ids)->orderBy('code')->pluck('id')->all();
        }

        if (! empty($data['item_ids'])) {
            return $stock->whereKey($data['item_ids'])->orderBy('code')->pluck('id')->all();
        }

        if (! empty($data['category_id'])) {
            return $stock->where('category_id', $data['category_id'])->orderBy('code')->pluck('id')->all();
        }

        return [];
    }

    /**
     * Expected quantities now: per batch with stock for batch items (and the
     * unbatched rest when there is any, or nothing batched), else per item.
     *
     * @param  list<string>  $itemIds
     * @return list<array{0: string, 1: ?string, 2: BigDecimal}>
     */
    private function snapshot(array $itemIds, string $locationId): array
    {
        $balances = Balance::query()->where('location_id', $locationId)->whereIn('item_id', $itemIds)->pluck('qty', 'item_id');
        $batched = ItemSetting::query()->whereIn('item_id', $itemIds)->where('tracking', ItemSetting::BATCH)->pluck('item_id')->all();
        $batches = BatchBalance::query()->where('location_id', $locationId)->whereIn('item_id', $batched)->where('qty', '<>', 0)
            ->orderBy('batch_id')->get()->groupBy('item_id');
        $lines = [];

        foreach ($itemIds as $itemId) {
            $total = Qty::of((string) ($balances[$itemId] ?? '0'));

            if (! in_array($itemId, $batched, true)) {
                $lines[] = [$itemId, null, $total];

                continue;
            }

            $rest = $total;

            foreach ($batches->get($itemId, collect()) as $batch) {
                $lines[] = [$itemId, $batch->batch_id, Qty::of((string) $batch->qty)];
                $rest = Qty::of($rest->minus((string) $batch->qty));
            }

            if (! $rest->isZero() || ! $batches->has($itemId)) {
                $lines[] = [$itemId, null, $rest];
            }
        }

        return $lines;
    }

    /** @param list<string> $statuses */
    private function assertStatus(StockCount $count, array $statuses): void
    {
        if (! in_array($count->status, $statuses, true)) {
            throw StockLedger::error(409, 'document_not_open');
        }
    }
}
