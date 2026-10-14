<?php

namespace Modules\Inventory\Stock;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Documents\Counts;
use Modules\Inventory\Events\StockMoved;
use Modules\Inventory\Models\BatchBalance;
use Modules\Inventory\Models\CostState;
use Modules\Inventory\Models\CountLine;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\Serial;
use Modules\Inventory\Models\StockCount;

/**
 * INV-04: the only writer of the stock ledger. post() turns a Posting into
 * movements in one transaction:
 *
 * 1. the company's base currency (CUR-02: locked by its first posting);
 * 2. once per source and kinds (an advisory lock, then a look for
 *    movements already there): a posting delivered twice moves stock once;
 * 3. the company's settings (made with the defaults on first use, without
 *    an audit entry, and the costing method fixed by the first movement);
 * 4. the cost states of the items, locked in item order (so concurrent
 *    postings never deadlock), then for each line: the cost (CostEngine),
 *    the location balance and batch balance with an atomic increment
 *    (`insert … on conflict do update … returning`), the negative-stock and
 *    count-freeze rules (INV-13, INV-09), the movement and its serials;
 * 5. reorder levels of the places touched (INV-11) and StockMoved, after
 *    commit (INV-16).
 *
 * Lock order: company (first posting only), audit head (callers audit
 * their documents before posting), cost states by item, layers, balances.
 */
class StockLedger
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CostEngine $costs,
        private readonly Reorder $reorder,
    ) {}

    /** @return list<Movement> the posting's movements (those already there when it was posted before) */
    public function post(Posting $posting): array
    {
        if ($posting->lines === []) {
            return [];
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($posting) {
            $currency = $this->baseCurrency($posting->companyId);
            $kinds = array_values(array_unique(array_map(fn (PostingLine $line) => $line->kind, $posting->lines)));

            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                'inventory:'.$posting->sourceType.':'.$posting->sourceId.':'.implode(',', $kinds),
            ]);

            $existing = Movement::query()->where('source_type', $posting->sourceType)->where('source_id', $posting->sourceId)
                ->whereIn('kind', $kinds)->orderBy('created_at')->orderBy('id')->get();

            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            // M1: a count starting at one of these places waits, or is waited for.
            foreach (collect($posting->lines)->pluck('locationId')->filter()->unique()->sort()->values() as $locationId) {
                DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock_shared(hashtextextended(?, 0))', [Counts::lockKey($locationId)]);
            }

            $settings = $this->settings($posting->companyId);
            $method = $settings->costing_method;
            $items = Item::query()->whereKey(array_unique(array_map(fn (PostingLine $l) => $l->itemId, $posting->lines)))->get()->keyBy('id');
            $states = $this->lockStates($posting->companyId, $items->keys()->sort()->values()->all(), $currency);
            $lines = $this->allocateBatches($posting->lines);
            $movements = [];
            $places = [];

            foreach ($lines as $line) {
                $state = $states[$line->itemId];
                $movementId = (string) Str::uuid7();
                $flags = $line->flags;
                $variance = BigInteger::zero();

                $adjustment = $line->kind === Movement::COST_ADJUSTMENT;

                if (! $adjustment) {
                    $this->checkFreeze($posting, $line, $flags);
                }

                if ($adjustment) {
                    // PRC-17: value only, on the receipt it adjusts; the share of units already sold is the variance.
                    $receipt = Movement::query()->findOrFail($line->reversesMovementId);
                    $result = $this->costs->adjust($state, $method, $line->cost ?? BigInteger::zero(), $receipt->id, BigDecimal::of((string) $receipt->qty));
                    $value = $result['cost'];
                    $variance = $result['variance'];
                    $flags = [...$flags, ...$result['flags']];
                } elseif ($line->kind === Movement::OPENING_REVERSAL && $line->reversesMovementId !== null) {
                    $original = Movement::query()->findOrFail($line->reversesMovementId);
                    // M2: checked again under the item's cost lock, so nothing can move it in between.
                    self::assertReversible($original);
                    $result = $this->costs->reverseReceipt($state, $method, $original->id, $line->qty->negated(), BigInteger::of((string) $original->value_minor));
                    $value = $result['cost']->negated();
                } elseif (in_array($line->kind, Movement::TRANSFERS, true)) {
                    // M4: an arrival carries its share of what left (the line's cost); a dispatch the company's unit cost now.
                    $unit = $this->costs->displayUnit($state);
                    $value = match (true) {
                        $line->cost !== null => $line->qty->isNegative() ? $line->cost->negated() : $line->cost,
                        $unit === null => BigInteger::zero(),
                        default => Qty::round($unit->multipliedBy($line->qty)),
                    };
                } elseif ($line->kind === Movement::TRANSFER_LOSS && $line->cost !== null) {
                    // M4: the loss carries the rest of what left; the company's cost goes down by what it takes now,
                    // and the difference is the loss's cost variance (taken now − carried).
                    $result = $this->costs->issue($state, $method, $line->qty->negated());
                    $value = $line->cost->negated();
                    $variance = $result['cost']->minus($line->cost);
                    $flags = [...$flags, ...$result['flags']];
                } elseif ($line->isInbound()) {
                    $result = $this->costs->receive($state, $method, $line->qty, $line->cost, $movementId, $posting->occurredAt, $line->setsLastCost);
                    $value = $result['cost'];
                    $variance = $result['variance'];
                    $flags = [...$flags, ...$result['flags']];
                } else {
                    $result = $this->costs->issue($state, $method, $line->qty->negated());
                    $value = $result['cost']->negated();
                    $flags = [...$flags, ...$result['flags']];
                }

                $after = null;

                if ($line->locationId !== null && ! $adjustment) {
                    $after = $this->moveBalance($posting->companyId, $line->locationId, $line->itemId, $line->qty);

                    $batchAfter = $line->batchId === null ? null : $this->moveBatchBalance($line->locationId, $line->itemId, $line->batchId, $line->qty);

                    // L1: a batch goes below zero as the location does (allowed, flagged; refused for manual moves when not allowed).
                    if ($line->qty->isNegative() && ($after->isNegative() || $batchAfter?->isNegative())) {
                        if ($posting->manual && ! $settings->allow_negative) {
                            throw self::error(422, 'insufficient_stock', ['item' => $items[$line->itemId]?->name ?? '']);
                        }

                        $flags[] = ['code' => 'negative_stock'];
                    }

                    $places[$line->itemId.'|'.$line->locationId] = [$line->itemId, $line->locationId];
                }

                $movements[] = Movement::create([
                    'id' => $movementId,
                    'company_id' => $posting->companyId,
                    'location_id' => $line->locationId,
                    'item_id' => $line->itemId,
                    'batch_id' => $line->batchId,
                    'kind' => $line->kind,
                    'qty' => (string) $line->qty,
                    'uom_id' => $line->uomId,
                    'uom_qty' => $line->uomQty === null ? null : (string) Qty::of($line->uomQty),
                    'value_minor' => (string) $value,
                    'currency' => $currency,
                    'cost_variance_minor' => (string) $variance,
                    'balance_after' => $after === null ? null : (string) $after,
                    'source_type' => $posting->sourceType,
                    'source_id' => $posting->sourceId,
                    'source_line_id' => $line->sourceLineId,
                    'reverses_movement_id' => $line->reversesMovementId,
                    'occurred_at' => $posting->occurredAt,
                    'flags' => array_values(array_unique($flags, SORT_REGULAR)),
                    'created_by' => $posting->userId,
                ]);

                $this->moveSerials($movementId, $line);
            }

            foreach ($states as $state) {
                $state->save();
            }

            $this->reorder->check(array_values($places));

            StockMoved::dispatch($this->tenants->require(), $posting->companyId, $currency, $posting->sourceType, $posting->sourceId, array_map(
                fn (Movement $m) => [
                    'id' => $m->id, 'kind' => $m->kind, 'item_id' => $m->item_id, 'location_id' => $m->location_id,
                    'qty' => Qty::show($m->qty), 'value_minor' => (string) $m->value_minor, 'cost_variance_minor' => (string) $m->cost_variance_minor,
                    'source_line_id' => $m->source_line_id,
                ],
                $movements,
            ));

            return $movements;
        });
    }

    /** INV-12, INV-13: the company's settings, made with the defaults (unaudited: no audit lock inside a posting); the method is fixed from now on. */
    private function settings(string $companyId): InventorySetting
    {
        DB::connection(TenantContext::CONNECTION)->table('inventory_settings')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenants->require(),
            'company_id' => $companyId,
            'costing_method' => InventorySetting::WEIGHTED_AVERAGE,
            'allow_negative' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection(TenantContext::CONNECTION)->table('inventory_settings')
            ->where('company_id', $companyId)->whereNull('costing_locked_at')->update(['costing_locked_at' => now()]);

        return InventorySetting::query()->where('company_id', $companyId)->firstOrFail();
    }

    /**
     * @param  list<string>  $itemIds  sorted
     * @return array<string, CostState>
     */
    private function lockStates(string $companyId, array $itemIds, string $currency): array
    {
        $states = [];

        foreach ($itemIds as $itemId) {
            DB::connection(TenantContext::CONNECTION)->table('inventory_cost_states')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenants->require(),
                'company_id' => $companyId,
                'item_id' => $itemId,
                'qty' => '0',
                'value_minor' => 0,
                'currency' => $currency,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $states[$itemId] = CostState::query()->where('company_id', $companyId)->where('item_id', $itemId)->lockForUpdate()->firstOrFail();
        }

        return $states;
    }

    /**
     * INV-06: an outbound `fefo` line of a batch item without a batch, split
     * over the location's batches with stock, earliest expiry first (then
     * oldest); what they do not cover stays unbatched and is flagged.
     *
     * @param  list<PostingLine>  $lines
     * @return list<PostingLine>
     */
    private function allocateBatches(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            if (! $line->fefo || $line->batchId !== null || ! $line->qty->isNegative() || $line->locationId === null
                || ItemSetting::query()->where('item_id', $line->itemId)->value('tracking') !== ItemSetting::BATCH) {
                $out[] = $line;

                continue;
            }

            $need = $line->qty->negated();
            $balances = BatchBalance::query()
                ->join('inventory_batches', 'inventory_batches.id', '=', 'inventory_batch_balances.batch_id')
                ->where('inventory_batch_balances.location_id', $line->locationId)
                ->where('inventory_batch_balances.item_id', $line->itemId)
                ->where('inventory_batch_balances.qty', '>', 0)
                ->orderByRaw('inventory_batches.expires_on asc nulls last')
                ->orderBy('inventory_batches.created_at')->orderBy('inventory_batches.id')
                ->get(['inventory_batch_balances.batch_id', 'inventory_batch_balances.qty']);

            foreach ($balances as $balance) {
                if (! $need->isPositive()) {
                    break;
                }

                $take = Qty::min(Qty::of((string) $balance->qty), $need);
                $out[] = $line->with(['qty' => $take->negated(), 'batchId' => $balance->batch_id, 'uomId' => null, 'uomQty' => null]);
                $need = Qty::of($need->minus($take));
            }

            if ($need->isPositive()) {
                $out[] = $line->with(['qty' => $need->negated(), 'uomId' => null, 'uomQty' => null, 'flags' => [...$line->flags, ['code' => 'no_batch_stock']]]);
            }
        }

        return $out;
    }

    /** INV-09: a running count freezes its items at its location for manual moves; others are flagged. */
    private function checkFreeze(Posting $posting, PostingLine $line, array &$flags): void
    {
        if ($line->locationId === null) {
            return;
        }

        $frozen = CountLine::query()
            ->join('inventory_counts', 'inventory_counts.id', '=', 'inventory_count_lines.count_id')
            ->where('inventory_counts.status', StockCount::COUNTING)
            ->where('inventory_counts.location_id', $line->locationId)
            ->where('inventory_count_lines.item_id', $line->itemId)
            ->exists();

        if (! $frozen || $posting->sourceType === 'inventory.count') {
            return;
        }

        if ($posting->manual) {
            throw self::error(422, 'count_in_progress');
        }

        $flags[] = ['code' => 'during_count'];
    }

    /**
     * INV-09: refuse a manual document naming items a running count has
     * frozen at $locationId (checked again when it posts).
     *
     * @param  list<string>  $itemIds
     */
    public static function assertNotFrozen(string $locationId, array $itemIds, string $field = 'lines'): void
    {
        $frozen = CountLine::query()
            ->join('inventory_counts', 'inventory_counts.id', '=', 'inventory_count_lines.count_id')
            ->where('inventory_counts.status', StockCount::COUNTING)
            ->where('inventory_counts.location_id', $locationId)
            ->whereIn('inventory_count_lines.item_id', $itemIds)
            ->exists();

        if ($frozen) {
            throw self::error(422, 'count_in_progress', field: $field);
        }
    }

    private function moveBalance(string $companyId, string $locationId, string $itemId, BigDecimal $qty): BigDecimal
    {
        $row = DB::connection(TenantContext::CONNECTION)->selectOne(
            'insert into inventory_balances (id, tenant_id, company_id, location_id, item_id, qty, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, now(), now())
             on conflict (tenant_id, location_id, item_id) do update set qty = inventory_balances.qty + excluded.qty, updated_at = now()
             returning qty',
            [(string) Str::uuid7(), $this->tenants->require(), $companyId, $locationId, $itemId, (string) $qty],
        );

        return Qty::of((string) $row->qty);
    }

    private function moveBatchBalance(string $locationId, string $itemId, string $batchId, BigDecimal $qty): BigDecimal
    {
        $row = DB::connection(TenantContext::CONNECTION)->selectOne(
            'insert into inventory_batch_balances (id, tenant_id, location_id, item_id, batch_id, qty, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, now(), now())
             on conflict (tenant_id, location_id, item_id, batch_id) do update set qty = inventory_batch_balances.qty + excluded.qty, updated_at = now()
             returning qty',
            [(string) Str::uuid7(), $this->tenants->require(), $locationId, $itemId, $batchId, (string) $qty],
        );

        return Qty::of((string) $row->qty);
    }

    /**
     * L5, CUR-02: the company's base currency, locked by its first posting.
     * Lock order everywhere in Inventory: the company row (first posting
     * only), then the audit chain head, then cost states. Document postings
     * call this first, before their audited writes.
     */
    public function baseCurrency(string $companyId): string
    {
        $company = Company::query()->findOrFail($companyId);

        return $company->base_currency_locked_at !== null ? $company->base_currency : app(BaseCurrencyLock::class)->lock($company);
    }

    /** INV-07: the serials the movement carried, and where each one is now. */
    private function moveSerials(string $movementId, PostingLine $line): void
    {
        if ($line->serialIds === []) {
            return;
        }

        foreach ($line->serialIds as $serialId) {
            DB::connection(TenantContext::CONNECTION)->table('inventory_movement_serials')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenants->require(),
                'movement_id' => $movementId,
                'serial_id' => $serialId,
                'created_at' => now(),
            ]);
        }

        [$status, $location] = match (true) {
            $line->kind === Movement::TRANSFER_OUT => [Serial::IN_TRANSIT, null],
            $line->qty->isPositive() => [Serial::IN_STOCK, $line->locationId],
            default => [Serial::ISSUED, null],
        };

        Serial::query()->whereKey($line->serialIds)->update(['status' => $status, 'location_id' => $location, 'updated_at' => now()]);
    }

    /**
     * IMP-02, INV-18: an opening line is taken back exactly only while
     * nothing later moved its item in the company (`opening_used`), and
     * only when it covered no shortfall: a receipt that covered negative
     * stock carries a cost variance that an exact reversal cannot undo
     * (409 `opening_has_variance`; adjust the stock instead).
     */
    public static function assertReversible(Movement $original): void
    {
        if (BigInteger::of((string) $original->cost_variance_minor)->isZero() === false) {
            throw self::error(409, 'opening_has_variance');
        }

        if (app(OpeningStock::class)->used($original)) {
            throw self::error(422, 'opening_used');
        }
    }

    /** @param array<string, string> $replace */
    public static function error(int $status, string $code, array $replace = [], ?string $field = null): ApiException
    {
        $message = __('inventory.errors.'.$code, $replace);

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
