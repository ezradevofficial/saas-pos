<?php

namespace Modules\Manufacturing\Boms;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Modules\Manufacturing\Models\BomLine;
use Modules\Manufacturing\Support\Qty;
use Modules\Manufacturing\Support\Units;

/**
 * MFG-04: a version's typed lines checked against their items, every error
 * reported at once on the line's field (`lines.0.qty`) with the first
 * error's code:
 *
 * - an item the person may see, shared or of the BOM's company (TEN-08),
 *   not the output item itself (`component_is_output`);
 * - a component is a stock item unless exploded (`not_a_stock_item`); a
 *   by-product is a stock item and is never exploded;
 * - a unit of the item (`uom_not_on_item`) and a positive quantity within
 *   its precision; yield 0 < y ≤ 100, wastage 0 ≤ w ≤ 500, a cost share
 *   0 ≤ s < 100 on by-products only, their total below 100
 *   (`cost_share_total`).
 */
class BomLines
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    private ?string $firstCode = null;

    public function __construct(private readonly Units $units) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{kind: string, item: Item, uom_id: string, qty: BigDecimal, qty_base: BigDecimal, yield_percent: string, wastage_percent: string, explode: bool, cost_share_percent: string, note: ?string}>
     */
    public function resolve(array $lines, string $companyId, string $outputItemId, ?User $user, string $prefix = 'lines'): array
    {
        $this->errors = [];
        $this->firstCode = null;
        $resolved = [];
        $shares = BigDecimal::zero();
        // Validated input may list lines out of their order (by the first rule each one met).
        ksort($lines);

        foreach (array_values($lines) as $index => $line) {
            $at = "{$prefix}.{$index}.";
            $kind = (string) ($line['kind'] ?? BomLine::COMPONENT);
            $item = Item::query()->whereKey((string) ($line['item_id'] ?? ''))->whereNull('archived_at')->first();

            if ($item === null || ($user !== null && ! $user->can('view', $item))) {
                $this->fail("{$at}item_id", 'item_unknown');

                continue;
            }

            if ($item->company_id !== null && $item->company_id !== $companyId) {
                $this->fail("{$at}item_id", 'item_other_company', ['item' => $item->name]);

                continue;
            }

            if ($item->id === $outputItemId) {
                $this->fail("{$at}item_id", 'component_is_output', ['item' => $item->name]);

                continue;
            }

            $explode = $kind === BomLine::COMPONENT && filter_var($line['explode'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! $explode && $item->type !== 'stock') {
                $this->fail("{$at}item_id", 'not_a_stock_item', ['item' => $item->name]);

                continue;
            }

            $uomId = (string) ($line['uom_id'] ?? $item->base_uom_id);
            $factor = $this->units->factor($item, $uomId);

            if ($factor === null) {
                $this->fail("{$at}uom_id", 'uom_not_on_item');

                continue;
            }

            try {
                $qty = BigDecimal::of((string) ($line['qty'] ?? ''));
            } catch (MathException) {
                $this->fail("{$at}qty", 'qty_invalid');

                continue;
            }

            if (! $qty->isPositive()) {
                $this->fail("{$at}qty", 'qty_invalid');

                continue;
            }

            if (! $this->units->fitsPrecision($uomId, $qty)) {
                $this->fail("{$at}qty", 'qty_precision');

                continue;
            }

            $yield = $this->percent($line['yield_percent'] ?? null, '100');
            $wastage = $this->percent($line['wastage_percent'] ?? null, '0');
            $share = $kind === BomLine::BY_PRODUCT ? $this->percent($line['cost_share_percent'] ?? null, '0') : BigDecimal::zero();

            if ($yield === null || ! $yield->isPositive() || $yield->isGreaterThan(100)) {
                $this->fail("{$at}yield_percent", 'percent_invalid');

                continue;
            }

            if ($wastage === null || $wastage->isNegative() || $wastage->isGreaterThan(500)) {
                $this->fail("{$at}wastage_percent", 'percent_invalid');

                continue;
            }

            if ($share === null || $share->isNegative() || $share->isGreaterThanOrEqualTo(100)) {
                $this->fail("{$at}cost_share_percent", 'percent_invalid');

                continue;
            }

            $shares = $shares->plus($share);

            if ($shares->isGreaterThanOrEqualTo(100)) {
                $this->fail("{$at}cost_share_percent", 'cost_share_total');

                continue;
            }

            $resolved[] = [
                'kind' => $kind, 'item' => $item, 'uom_id' => $uomId, 'qty' => Qty::of($qty), 'qty_base' => Qty::of($qty->multipliedBy($factor)),
                'yield_percent' => (string) $yield->toScale(4), 'wastage_percent' => (string) $wastage->toScale(4), 'explode' => $explode,
                'cost_share_percent' => (string) $share->toScale(4), 'note' => isset($line['note']) ? (string) $line['note'] : null,
            ];
        }

        if ($this->errors !== []) {
            throw new ApiException(422, (string) $this->firstCode, array_values($this->errors)[0][0], $this->errors);
        }

        return $resolved;
    }

    private function percent(mixed $value, string $default): ?BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::of($default);
        }

        try {
            $decimal = BigDecimal::of((string) $value);
        } catch (MathException) {
            return null;
        }

        return $decimal->getScale() > 4 ? null : $decimal;
    }

    private function fail(string $field, string $code, array $replace = []): void
    {
        $this->errors[$field][] = __('manufacturing.errors.'.$code, $replace);
        $this->firstCode ??= $code;
    }
}
