<?php

namespace Modules\Inventory\Stock;

use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Serial;

/**
 * INV-01, INV-03, INV-06, INV-07: typed stock lines (opening stock,
 * adjustments, transfers) checked against their items: a stock item, a
 * unit of the item within its precision, a batch for batch items (a number,
 * with expiry when tracked, coming in; an existing batch going out), one
 * serial per unit for serial items (new coming in; in stock at the place
 * going out). Untracked items ignore batches and serials. Errors name the
 * line's field (`lines.0.qty`) and are all reported at once (422, the
 * first error's code).
 */
class StockInput
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    private ?string $firstCode = null;

    public function __construct(private readonly Units $units) {}

    /**
     * Check a set of lines; throws when any is wrong.
     *
     * @param  list<array<string, mixed>>  $lines  each with item_id, uom_id?, qty, batch_id?, batch_number?, expires_on?, serials?
     * @param  callable(int, BigDecimal): bool  $inbound  whether line $index (with its typed quantity) brings stock in
     * @return list<ResolvedLine>
     */
    public function lines(array $lines, string $prefix, callable $inbound, string $locationId, bool $signed = false): array
    {
        $this->errors = [];
        $this->firstCode = null;
        $resolved = [];

        foreach (array_values($lines) as $index => $line) {
            $resolved[] = $this->one($line, "{$prefix}.{$index}.", fn (BigDecimal $qty) => $inbound($index, $qty), $locationId, $signed);
        }

        $this->throwIfAny();

        return array_values(array_filter($resolved));
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  callable(BigDecimal): bool  $inbound
     */
    public function single(array $line, callable $inbound, string $locationId): ResolvedLine
    {
        $this->errors = [];
        $this->firstCode = null;
        $resolved = $this->one($line, '', $inbound, $locationId, false);
        $this->throwIfAny();

        return $resolved;
    }

    /** @param callable(BigDecimal): bool $inbound */
    private function one(array $line, string $at, callable $inbound, string $locationId, bool $signed): ?ResolvedLine
    {
        $item = Item::query()->whereKey((string) ($line['item_id'] ?? ''))->whereNull('archived_at')->first();

        if ($item === null) {
            $this->fail("{$at}item_id", 'item_unknown');

            return null;
        }

        if ($item->type !== 'stock') {
            $this->fail("{$at}item_id", 'not_a_stock_item', ['item' => $item->name]);

            return null;
        }

        $uomId = (string) ($line['uom_id'] ?? $item->base_uom_id);
        $factor = $this->units->factor($item, $uomId);

        if ($factor === null) {
            $this->fail("{$at}uom_id", 'uom_not_on_item');

            return null;
        }

        try {
            $qty = BigDecimal::of((string) ($line['qty'] ?? ''));
        } catch (MathException) {
            $this->fail("{$at}qty", 'qty_invalid');

            return null;
        }

        if ($qty->isZero() || (! $signed && $qty->isNegative())) {
            $this->fail("{$at}qty", 'qty_invalid');

            return null;
        }

        if (! $this->units->fitsPrecision($uomId, $qty->abs())) {
            $this->fail("{$at}qty", 'qty_precision');

            return null;
        }

        $base = $this->units->toBase($qty->abs(), $factor);
        $settings = ItemSetting::query()->where('item_id', $item->id)->first();
        $tracking = $settings?->tracking ?? ItemSetting::NONE;
        $trackExpiry = (bool) ($settings?->track_expiry ?? false);
        $coming = $inbound($qty);
        $batchId = null;
        $batchNumber = null;
        $expiresOn = null;
        $serials = [];

        if ($tracking === ItemSetting::BATCH) {
            [$batchId, $batchNumber, $expiresOn] = $this->batch($item, $line, $at, $coming, $trackExpiry);
        }

        if ($tracking === ItemSetting::SERIAL) {
            $serials = $this->serials($item, $line, $at, $coming, $base, $locationId);
        }

        return new ResolvedLine($item, $uomId, $qty, $base, $tracking, $trackExpiry, $batchId, $batchNumber, $expiresOn, $serials);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function batch(Item $item, array $line, string $at, bool $coming, bool $trackExpiry): array
    {
        $batchId = isset($line['batch_id']) ? (string) $line['batch_id'] : null;

        if ($batchId !== null) {
            $batch = Batch::query()->whereKey($batchId)->where('item_id', $item->id)->first();

            if ($batch === null) {
                $this->fail("{$at}batch_id", 'batch_unknown');

                return [null, null, null];
            }

            return [$batch->id, $batch->batch_number, $batch->expires_on?->toDateString()];
        }

        $number = trim((string) ($line['batch_number'] ?? ''));

        if (! $coming || $number === '') {
            $this->fail($coming ? "{$at}batch_number" : "{$at}batch_id", 'batch_required');

            return [null, null, null];
        }

        $existing = Batch::query()->where('item_id', $item->id)->whereRaw('lower(batch_number) = lower(?)', [$number])->first();
        $expires = isset($line['expires_on']) ? (string) $line['expires_on'] : $existing?->expires_on?->toDateString();

        if ($trackExpiry && $expires === null) {
            $this->fail("{$at}expires_on", 'expiry_required');
        }

        return [$existing?->id, $existing?->batch_number ?? $number, $expires];
    }

    /** @return list<string> */
    private function serials(Item $item, array $line, string $at, bool $coming, BigDecimal $base, string $locationId): array
    {
        $serials = array_values(array_unique(array_map(fn ($s) => trim((string) $s), (array) ($line['serials'] ?? []))));
        $serials = array_values(array_filter($serials, fn (string $s) => $s !== ''));

        if ($base->strippedOfTrailingZeros()->getScale() > 0 || count($serials) !== $base->toBigInteger()->toInt()) {
            $this->fail("{$at}serials", 'serials_required', ['count' => Qty::show($base)]);

            return [];
        }

        $known = Serial::query()->where('item_id', $item->id)
            ->whereIn(DB::raw('lower(serial_number)'), array_map(fn (string $s) => Str::lower($s), $serials))
            ->get()->keyBy(fn (Serial $s) => Str::lower($s->serial_number));

        foreach ($serials as $serial) {
            $found = $known[Str::lower($serial)] ?? null;

            if ($coming && $found !== null && $found->status !== Serial::ISSUED) {
                $this->fail("{$at}serials", 'serial_exists', ['serial' => $serial]);
            }

            if (! $coming && ($found === null || $found->status !== Serial::IN_STOCK || $found->location_id !== $locationId)) {
                $this->fail("{$at}serials", 'serial_not_in_stock', ['serial' => $serial]);
            }
        }

        return $serials;
    }

    /**
     * The batch for a line coming in, made on first use (inside the
     * posting's transaction).
     */
    public function batchFor(ResolvedLine $line): ?string
    {
        if ($line->tracking !== ItemSetting::BATCH) {
            return null;
        }

        if ($line->batchId !== null) {
            return $line->batchId;
        }

        $existing = Batch::query()->where('item_id', $line->item->id)->whereRaw('lower(batch_number) = lower(?)', [$line->batchNumber])->first();

        if ($existing !== null) {
            if ($existing->expires_on === null && $line->expiresOn !== null) {
                $existing->forceFill(['expires_on' => $line->expiresOn])->save();
            }

            return $existing->id;
        }

        return Batch::create(['item_id' => $line->item->id, 'batch_number' => $line->batchNumber, 'expires_on' => $line->expiresOn])->id;
    }

    /**
     * The serials' ids for a posting: made (or put back in stock) coming in,
     * found going out. Inside the posting's transaction.
     *
     * @param  list<string>  $serials
     * @return list<string>
     */
    public function serialIds(string $itemId, array $serials, bool $coming, ?string $locationId): array
    {
        $ids = [];

        foreach ($serials as $number) {
            $serial = Serial::query()->where('item_id', $itemId)->whereRaw('lower(serial_number) = lower(?)', [$number])->lockForUpdate()->first();

            if ($serial === null && $coming) {
                $serial = Serial::create(['id' => (string) Str::uuid7(), 'item_id' => $itemId, 'serial_number' => $number, 'status' => Serial::IN_STOCK, 'location_id' => $locationId]);
            }

            if ($serial === null || ($coming && ! $serial->wasRecentlyCreated && $serial->status !== Serial::ISSUED)
                || (! $coming && ($serial->status !== Serial::IN_STOCK || $serial->location_id !== $locationId))) {
                throw StockLedger::error(422, $coming ? 'serial_exists' : 'serial_not_in_stock', ['serial' => $number]);
            }

            $ids[] = $serial->id;
        }

        return $ids;
    }

    private function fail(string $field, string $code, array $replace = []): void
    {
        $this->errors[$field][] = __('inventory.errors.'.$code, $replace);
        $this->firstCode ??= $code;
    }

    private function throwIfAny(): void
    {
        if ($this->errors === []) {
            return;
        }

        $first = array_values($this->errors)[0][0];

        throw new ApiException(422, (string) $this->firstCode, $first, $this->errors);
    }

    /** Run $fn in the tenant's transaction. */
    public static function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
