<?php

namespace Modules\Inventory\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\MasterData\Items\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Inventory\Http\Lists\ReorderRuleList;
use Modules\Inventory\Http\Requests\ListReorderRulesRequest;
use Modules\Inventory\Http\Requests\UpsertReorderRuleRequest;
use Modules\Inventory\Http\Resources\ReorderRuleResource;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Stock\ItemStock;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** INV-11: reorder rules at the reader's locations (RBAC-04). */
class ReorderController
{
    public function index(ListReorderRulesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = self::query();
        $request->applyFilters($query);

        match ($request->validated('status', 'active')) {
            'active' => $query->whereNull('inventory_reorder_rules.archived_at'),
            'archived' => $query->whereNotNull('inventory_reorder_rules.archived_at'),
            default => null,
        };

        if ($request->validated('flag') === 'low') {
            $query->whereRaw('coalesce((select b.qty from inventory_balances b where b.item_id = inventory_reorder_rules.item_id and b.location_id = inventory_reorder_rules.location_id), 0) <= inventory_reorder_rules.reorder_level');
        }

        $request->applySearch($query, ['item' => 'items.name', 'code' => 'items.code']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ReorderRuleResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function upsert(UpsertReorderRuleRequest $request, ItemStock $stock): JsonResponse
    {
        $rule = $stock->reorderRule(
            Item::query()->findOrFail($request->validated('item_id')),
            (string) $request->validated('location_id'),
            $request->validated('reorder_level'),
            $request->validated('reorder_qty'),
        );

        return new JsonResponse(['data' => $rule === null ? null : ReorderRuleResource::make(self::query()->whereKey($rule->id)->firstOrFail())->resolve($request)]);
    }

    /** @return Builder<ReorderRule> rules with their item and the stock at their place */
    private static function query(): Builder
    {
        return ReorderRule::query()->select('inventory_reorder_rules.*')
            ->join('items', 'items.id', '=', 'inventory_reorder_rules.item_id')
            ->addSelect(['on_hand' => Balance::query()->select('qty')
                ->whereColumn('inventory_balances.item_id', 'inventory_reorder_rules.item_id')
                ->whereColumn('inventory_balances.location_id', 'inventory_reorder_rules.location_id')->limit(1)])
            ->with(ReorderRuleList::RELATIONS);
    }
}
