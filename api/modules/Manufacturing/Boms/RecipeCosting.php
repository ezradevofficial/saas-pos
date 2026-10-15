<?php

namespace Modules\Manufacturing\Boms;

use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Inventory\Contracts\StockCosts;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-06: what a BOM in force costs now, from Inventory's current unit
 * costs of its exploded leaves (Inventory's StockCosts contract: nothing
 * while Inventory is off), in minor units of the company's base currency:
 * the batch's cost is Σ leaf quantity × unit cost, rounded once (half up),
 * and the cost per output unit is that ÷ the batch's output quantity
 * (half up). Leaves without a known cost are named (`uncosted`) and leave
 * the cost incomplete. The menu item's price comes from the company's
 * default price list in its base currency (as listed, with whether it
 * includes tax), and the cost's share of it.
 */
class RecipeCosting
{
    public function __construct(
        private readonly Versions $versions,
        private readonly Explosion $explosion,
        private readonly PriceResolver $prices,
    ) {}

    /** @return array<string, mixed>|null null when no version is in force */
    public function of(Bom $bom, ?CarbonImmutable $at = null): ?array
    {
        $at ??= CarbonImmutable::now();
        $version = $this->versions->ofBom($bom->id, $at);

        if ($version === null) {
            return null;
        }

        $version->setRelation('bom', $bom);

        try {
            $exploded = $this->explosion->explode($version, BigDecimal::of((string) $version->output_qty_base), $at);
        } catch (ApiException $e) {
            return ['version' => $version, 'error' => $e->errorCode];
        }

        return ['version' => $version, ...$this->cost($bom->company_id, $exploded['leaves'], $version, $bom->item_id)];
    }

    /**
     * @param  list<array{item_id: string, qty_base: BigDecimal}>  $leaves
     * @return array{currency: string, batch_cost_minor: ?string, unit_cost_minor: ?string, leaves: list<array<string, mixed>>, uncosted: list<string>, price: ?array<string, mixed>, cost_share_percent: ?string, error: null}
     */
    public function cost(string $companyId, array $leaves, BomVersion $version, string $itemId): array
    {
        $company = Company::query()->findOrFail($companyId);
        $costs = class_exists(StockCosts::class) ? app(StockCosts::class)->unitCosts($companyId, array_column($leaves, 'item_id')) : [];
        $total = BigDecimal::zero();
        $rows = [];
        $uncosted = [];

        foreach ($leaves as $leaf) {
            $unit = $costs[$leaf['item_id']]['unit_cost_minor'] ?? null;
            $value = $unit === null ? null : BigDecimal::of($unit)->multipliedBy($leaf['qty_base']);
            $total = $value === null ? $total : $total->plus($value);

            if ($unit === null) {
                $uncosted[] = $leaf['item_id'];
            }

            $rows[] = [
                'item_id' => $leaf['item_id'], 'qty_base' => Qty::show($leaf['qty_base']),
                'unit_cost_minor' => $unit, 'cost_minor' => $value === null ? null : (string) $value->toScale(0, RoundingMode::HalfUp),
            ];
        }

        $known = $costs !== [] || $leaves === [];
        $batch = $known ? $total->toScale(0, RoundingMode::HalfUp) : null;
        $perUnit = $batch === null ? null : $batch->dividedBy((string) $version->output_qty, 0, RoundingMode::HalfUp);
        $item = Item::query()->find($itemId);
        $price = $item === null ? null : $this->prices->priceFor($item, $version->output_uom_id, $company);
        $share = $perUnit === null || $price === null || BigDecimal::of($price->money->minor())->isZero()
            ? null
            : (string) $perUnit->multipliedBy(100)->dividedBy($price->money->minor(), 1, RoundingMode::HalfUp);

        return [
            'currency' => (string) $company->base_currency,
            'batch_cost_minor' => $batch === null ? null : (string) $batch,
            'unit_cost_minor' => $perUnit === null ? null : (string) $perUnit,
            'leaves' => $rows,
            'uncosted' => $uncosted,
            'price' => $price === null ? null : ['amount_minor' => $price->money->minor(), 'currency' => $price->money->currency(), 'tax_inclusive' => $price->taxInclusive],
            'cost_share_percent' => $share,
            'error' => null,
        ];
    }
}
