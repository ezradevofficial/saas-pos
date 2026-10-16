<?php

namespace Modules\Inventory\Contracts;

use App\Core\Rbac\ModuleRegistry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\CostState;
use Modules\Inventory\Stock\CostEngine;

/**
 * INV-12 for other modules (Manufacturing's recipe costing, MFG-06): the
 * current unit cost of items at a company, as Inventory reads it (its
 * value ÷ quantity while some is held, else the last receipt's unit cost),
 * in minor units of the company's base currency per base unit, a decimal
 * string with 6 places. Inventory offers it as POS offers SaleStockLines,
 * so the asking module never reads Inventory's tables. Nothing while
 * Inventory is inactive for the tenant (RBAC-08).
 */
final class StockCosts
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly CostEngine $costs,
    ) {}

    /**
     * @param  list<string>  $itemIds
     * @return array<string, array{unit_cost_minor: string, currency: string}> by item id; items without a cost are left out
     */
    public function unitCosts(string $companyId, array $itemIds): array
    {
        if ($itemIds === [] || ! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
            return [];
        }

        $costs = [];

        foreach (CostState::query()->where('company_id', $companyId)->whereIn('item_id', array_values(array_unique($itemIds)))->get() as $state) {
            $unit = $this->costs->averageUnit($state);

            if ($unit !== null) {
                $costs[(string) $state->item_id] = [
                    'unit_cost_minor' => (string) BigDecimal::of($unit)->toScale(6, RoundingMode::HalfUp),
                    'currency' => (string) $state->currency,
                ];
            }
        }

        return $costs;
    }
}
