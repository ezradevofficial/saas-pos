<?php

namespace Modules\Manufacturing\Http\Resources;

use App\Core\MasterData\Items\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Boms\RecipeCosting;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-06: a BOM in force now and what it costs from Inventory's current
 * unit costs (batch and per output unit), its price and the cost's share
 * of it; leaves without a cost are named. Costs only for holders of
 * `manufacturing.cost.view` in the BOM's company (RBAC-05).
 *
 * @mixin Bom
 */
class RecipeCostingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Bom $bom */
        $bom = $this->resource;
        $costing = app(RecipeCosting::class)->of($bom);
        $costs = Costs::inCompany($request, $bom->company_id);
        $version = $costing['version'] ?? null;
        $currency = $costing['currency'] ?? null;
        $uncosted = $costing['uncosted'] ?? [];

        return [
            'id' => $bom->id,
            'kind' => $bom->kind,
            'company' => Refs::named($bom->company),
            'item' => Refs::item($bom->item),
            'version' => $version === null ? null : ['id' => $version->id, 'version_no' => $version->version_no],
            'output_qty' => $version === null ? null : Qty::show($version->output_qty),
            'output_uom' => $version === null ? null : Refs::uom($version->outputUom),
            'error' => $costing['error'] ?? ($version === null ? 'no_bom_in_force' : null),
            'unit_cost' => $costs ? Costs::money($costing['unit_cost_minor'] ?? null, $currency) : null,
            'batch_cost' => $costs ? Costs::money($costing['batch_cost_minor'] ?? null, $currency) : null,
            'price' => $costs ? ($costing['price'] ?? null) : null,
            'cost_share_percent' => $costs ? Qty::show($costing['cost_share_percent'] ?? null) : null,
            'leaves' => array_map(fn (array $leaf) => [
                'item' => Refs::item(Item::query()->find($leaf['item_id'])),
                'qty_base' => $leaf['qty_base'],
                'unit_cost_minor' => $costs ? $leaf['unit_cost_minor'] : null,
                'cost' => $costs ? Costs::money($leaf['cost_minor'], $currency) : null,
            ], $costing['leaves'] ?? []),
            'uncosted' => Item::query()->whereKey($uncosted)->get()->map(fn (Item $item) => Refs::item($item))->values()->all(),
        ];
    }
}
