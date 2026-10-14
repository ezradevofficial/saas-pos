<?php

namespace Modules\Stores\Stock;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * STR-02, STR-07, MD-02: typed item lines checked against their items: a
 * stock item the person may see, shared or of the store's company
 * (TEN-08), a unit of the item (core units and factors), a quantity other
 * than zero (positive unless signed) within the unit's precision (whole
 * numbers for counted units, 3 decimals otherwise). Every error is
 * reported at once, on the line's field (`lines.0.qty`), with the first
 * error's code.
 */
class Lines
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    private ?string $firstCode = null;

    /**
     * @param  list<array<string, mixed>>  $lines  each with item_id, uom_id?, qty
     * @return list<array{item: Item, uom_id: string, qty: BigDecimal, qty_base: BigDecimal}> qty keeps its sign; qty_base is positive
     */
    public function resolve(array $lines, string $companyId, ?User $user, bool $signed = false, string $prefix = 'lines'): array
    {
        $this->errors = [];
        $this->firstCode = null;
        $resolved = [];

        foreach (array_values($lines) as $index => $line) {
            $at = "{$prefix}.{$index}.";
            $item = Item::query()->whereKey((string) ($line['item_id'] ?? ''))->whereNull('archived_at')->first();

            if ($item === null || ($user !== null && ! $user->can('view', $item))) {
                $this->fail("{$at}item_id", 'item_unknown');

                continue;
            }

            if ($item->company_id !== null && $item->company_id !== $companyId) {
                $this->fail("{$at}item_id", 'item_other_company', ['item' => $item->name]);

                continue;
            }

            if ($item->type !== 'stock') {
                $this->fail("{$at}item_id", 'not_a_stock_item', ['item' => $item->name]);

                continue;
            }

            $uomId = (string) ($line['uom_id'] ?? $item->base_uom_id);
            $factor = self::factor($item, $uomId);

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

            if ($qty->isZero() || (! $signed && $qty->isNegative())) {
                $this->fail("{$at}qty", 'qty_invalid');

                continue;
            }

            if (! self::fitsPrecision($uomId, $qty->abs())) {
                $this->fail("{$at}qty", 'qty_precision');

                continue;
            }

            $resolved[] = ['item' => $item, 'uom_id' => $uomId, 'qty' => $qty, 'qty_base' => Qty::of($qty->abs()->multipliedBy($factor))];
        }

        if ($this->errors !== []) {
            throw new ApiException(422, (string) $this->firstCode, array_values($this->errors)[0][0], $this->errors);
        }

        return $resolved;
    }

    /** The base units one $uomId holds for $item (1 for its base unit), or null when the item has no such unit. */
    public static function factor(Item $item, ?string $uomId): ?BigDecimal
    {
        if ($uomId === null || $uomId === $item->base_uom_id) {
            return BigDecimal::one();
        }

        $factor = ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->value('factor');

        return $factor === null ? null : BigDecimal::of((string) $factor);
    }

    /** Whether $qty fits the precision of $uomId: whole numbers for counted units, 3 decimals for the rest. */
    public static function fitsPrecision(string $uomId, BigDecimal $qty): bool
    {
        $kind = Uom::query()->whereKey($uomId)->value('kind');

        return $qty->strippedOfTrailingZeros()->getScale() <= ($kind === 'count' ? 0 : 3);
    }

    private function fail(string $field, string $code, array $replace = []): void
    {
        $this->errors[$field][] = __('stores.errors.'.$code, $replace);
        $this->firstCode ??= $code;
    }
}
