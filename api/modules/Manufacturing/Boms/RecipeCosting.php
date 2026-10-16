<?php

namespace Modules\Manufacturing\Boms;

use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\MasterData\Prices\ResolvedPrice;
use App\Core\MasterData\Taxes\ItemTaxStatus;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRateMissing;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Inventory\Contracts\StockCosts;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Models\Setting;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-06: what a BOM in force costs now, in minor units of the company's
 * base currency (owner decision B, 2026-10-16: full cost against the net
 * price).
 *
 * - Ingredients: Inventory's current unit costs of the exploded leaves
 *   (Inventory's StockCosts contract: nothing while Inventory is off),
 *   Σ leaf quantity × unit cost, rounded once (half up). Leaves without a
 *   known cost are named (`uncosted`) and leave the cost incomplete.
 * - Labour and overhead where the company sets them: the version's minutes
 *   (scaled to the quantity) × the company's rates ÷ 60, half up, as an
 *   order is costed (MFG-16).
 * - Less the by-products' credit: each takes `cost_share_percent` of that
 *   cost (rounded down), as MFG-16 relieves them.
 * - Per output unit: the batch cost ÷ the output quantity (half up).
 *
 * The menu item's price comes from the company's default price list in its
 * base currency (as listed, with whether it includes tax). The cost's share
 * is of the net price: a tax-inclusive price less the tax of the item's core
 * tax code (TaxCalculator, MD-03); a price whose tax cannot be worked out
 * (no tax category or code, rate needed) has no net price, named by
 * `net_price_reason`, and no share.
 */
class RecipeCosting
{
    public function __construct(
        private readonly Versions $versions,
        private readonly Explosion $explosion,
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $tax,
        private readonly ItemTaxStatus $taxStatus,
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

        return ['version' => $version, ...$this->cost($bom->company_id, $exploded['leaves'], $version, $bom->item_id, $exploded['by_products'], null, $at)];
    }

    /**
     * @param  list<array{item_id: string, qty_base: BigDecimal}>  $leaves
     * @param  list<array{cost_share_percent: string}>  $byProducts
     * @param  BigDecimal|null  $demandBase  the exploded quantity in base units (the version's output when null)
     * @return array{currency: string, materials_cost_minor: ?string, labour_cost_minor: string, overhead_cost_minor: string, by_product_credit_minor: ?string, batch_cost_minor: ?string, unit_cost_minor: ?string, leaves: list<array<string, mixed>>, uncosted: list<string>, price: ?array<string, mixed>, cost_share_percent: ?string, error: null}
     */
    public function cost(string $companyId, array $leaves, BomVersion $version, string $itemId, array $byProducts = [], ?BigDecimal $demandBase = null, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
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

        // The quantity costed, as a multiple of the version's batch.
        $outputBase = BigDecimal::of((string) $version->output_qty_base);
        $scale = $demandBase === null ? BigDecimal::one() : $demandBase->dividedBy($outputBase, 12, RoundingMode::HalfUp);
        $minutes = BigDecimal::of((int) $version->labour_minutes)->multipliedBy($scale);
        $settings = Setting::query()->where('company_id', $companyId)->first();
        $perMinutes = fn (?string $rate) => $rate === null ? BigDecimal::zero() : BigDecimal::of($rate)->multipliedBy($minutes)->dividedBy(60, 0, RoundingMode::HalfUp);
        $labour = $perMinutes($settings === null ? null : (string) $settings->labour_rate_minor);
        $overhead = $perMinutes($settings === null ? null : (string) $settings->overhead_rate_minor);

        $known = $costs !== [] || $leaves === [];
        $materials = $known ? $total->toScale(0, RoundingMode::HalfUp) : null;
        $gross = $materials?->plus($labour)->plus($overhead);
        $credit = $gross === null ? null : array_reduce(
            $byProducts,
            fn (BigDecimal $sum, array $line) => $sum->plus(Qty::percentDown($gross->toBigInteger(), (string) $line['cost_share_percent'])),
            BigDecimal::zero(),
        );
        $batch = $gross?->minus($credit);
        $units = BigDecimal::of((string) $version->output_qty)->multipliedBy($scale);
        $perUnit = $batch === null || $units->isZero() ? null : $batch->dividedBy($units, 0, RoundingMode::HalfUp);
        $item = Item::query()->find($itemId);
        $price = $item === null ? null : $this->prices->priceFor($item, $version->output_uom_id, $company);
        [$net, $netReason] = $price === null ? [null, null] : $this->netPrice($company, $item, $price, $at);
        $share = $perUnit === null || $net === null || $net->isZero()
            ? null
            : (string) $perUnit->multipliedBy(100)->dividedBy($net, 1, RoundingMode::HalfUp);

        return [
            'currency' => (string) $company->base_currency,
            'materials_cost_minor' => $materials === null ? null : (string) $materials,
            'labour_cost_minor' => (string) $labour,
            'overhead_cost_minor' => (string) $overhead,
            'by_product_credit_minor' => $credit === null ? null : (string) $credit,
            'batch_cost_minor' => $batch === null ? null : (string) $batch,
            'unit_cost_minor' => $perUnit === null ? null : (string) $perUnit,
            'leaves' => $rows,
            'uncosted' => $uncosted,
            'price' => $price === null ? null : [
                'amount_minor' => $price->money->minor(), 'currency' => $price->money->currency(), 'tax_inclusive' => $price->taxInclusive,
                'net_minor' => $net === null ? null : (string) $net, 'net_price_reason' => $netReason,
            ],
            'cost_share_percent' => $share,
            'error' => null,
        ];
    }

    /**
     * The price before tax: as listed when the list excludes tax; else less
     * the tax of the item's core tax code at $at (TaxCalculator, inclusive).
     *
     * @return array{0: ?BigDecimal, 1: ?string} the net in minor units, or null and why
     */
    private function netPrice(Company $company, Item $item, ResolvedPrice $price, CarbonImmutable $at): array
    {
        if (! $price->taxInclusive) {
            return [BigDecimal::of($price->money->minor()), null];
        }

        $status = ItemTaxStatus::of($item->tax_category_id, $this->taxStatus->forCompany($company, $at));
        $code = $status['tax_code_id'] === null ? null : TaxCode::query()->find($status['tax_code_id']);

        if ($code === null || ($status['reason'] !== null && $status['reason'] !== ItemTaxStatus::TAX_RATE_NEEDED)) {
            return [null, $status['reason'] ?? ItemTaxStatus::TAX_CODE_MISSING];
        }

        try {
            return [BigDecimal::of($this->tax->forLine($price->money, [$code], true, $at)->net->minor()), null];
        } catch (TaxRateMissing) {
            return [null, ItemTaxStatus::TAX_RATE_NEEDED];
        }
    }
}
