<?php

namespace Modules\Inventory\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Inventory\Http\Lists\MovementList;
use Modules\Inventory\Http\Lists\StockList;
use Modules\Inventory\Http\Lists\ValuationList;
use Modules\Inventory\Http\Requests\ItemRequest;
use Modules\Inventory\Http\Requests\ListMovementsRequest;
use Modules\Inventory\Http\Requests\ListStockRequest;
use Modules\Inventory\Http\Requests\ListValuationRequest;
use Modules\Inventory\Http\Requests\StoreOpeningStockRequest;
use Modules\Inventory\Http\Resources\MovementResource;
use Modules\Inventory\Http\Resources\Refs;
use Modules\Inventory\Http\Resources\StockResource;
use Modules\Inventory\Http\Resources\ValuationResource;
use Modules\Inventory\Http\Resources\Values;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\BatchBalance;
use Modules\Inventory\Models\CostState;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Models\Serial;
use Modules\Inventory\Models\Variant;
use Modules\Inventory\Stock\ItemStock;
use Modules\Inventory\Stock\OpeningStock;
use Modules\Inventory\Stock\Qty;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * INV-01, INV-02, INV-04, INV-05, INV-18 to INV-20: stock on hand, the
 * ledger, an item's stock detail and settings, opening stock and the
 * valuation report, scoped by location (RBAC-04) with values for
 * valuation holders only (RBAC-05).
 */
class StockController
{
    public function index(ListStockRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Balance::query()->select('inventory_balances.*')
            ->join('items', 'items.id', '=', 'inventory_balances.item_id')
            ->with(StockList::RELATIONS)
            ->addSelect(self::ruleColumns('inventory_balances'))
            ->addSelect(self::costColumns('inventory_balances'));
        $request->applyFilters($query);

        if ($request->filled('category')) {
            $query->where('items.category_id', $request->validated('category'));
        }

        if ($request->validated('flag') === 'low') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('inventory_reorder_rules')
                ->whereColumn('inventory_reorder_rules.item_id', 'inventory_balances.item_id')
                ->whereColumn('inventory_reorder_rules.location_id', 'inventory_balances.location_id')
                ->whereNull('inventory_reorder_rules.archived_at')
                ->whereColumn('inventory_balances.qty', '<=', 'inventory_reorder_rules.reorder_level'));
        }

        $request->applySearch($query, ['item' => 'items.name', 'code' => 'items.code']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return StockResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function movements(ListMovementsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        return $this->ledger($request, $export, null);
    }

    public function itemMovements(ListMovementsRequest $request, ListExport $export, Item $item): AnonymousResourceCollection|StreamedResponse
    {
        abort_unless($request->user()->can('view', $item), 404);

        return $this->ledger($request, $export, $item);
    }

    private function ledger(ListMovementsRequest $request, ListExport $export, ?Item $item): AnonymousResourceCollection|StreamedResponse
    {
        $query = Movement::query()->with(MovementList::RELATIONS);
        $request->applyFilters($query, dateColumn: 'occurred_at');
        $itemId = $item?->id ?? $request->validated('item');

        if ($itemId !== null) {
            $query->where('inventory_movements.item_id', $itemId);
        }

        if ($request->filled('source')) {
            $query->where('inventory_movements.source_id', $request->validated('source'));
        }

        if ($request->filled('kind')) {
            $query->where('inventory_movements.kind', $request->validated('kind'));
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q
                ->whereIn('item_id', Item::query()->where(fn ($i) => $i->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like))->select('id'))
                ->orWhereIn('batch_id', fn ($b) => $b->select('id')->from('inventory_batches')->where('batch_number', 'ilike', $like)));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return MovementResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** INV-01, INV-02, INV-05 to INV-07, INV-11: one item's stock where the reader sees it. */
    public function item(ItemRequest $request, Item $item): JsonResponse
    {
        $item = $request->item()->load('baseUom');
        $visible = app(ScopeResolver::class)->visibleIds($request->user(), 'inventory.stock.view');
        $at = fn (Builder $q, string $column = 'location_id') => $visible->all ? $q : $q->whereIn($column, $visible->idsFor(Scope::LOCATION));
        $settings = ItemSetting::query()->where('item_id', $item->id)->first();
        $variant = Variant::query()->whereNull('archived_at')->where('item_id', $item->id)->with('parent')->first();
        $variants = Variant::query()->whereNull('archived_at')->where('parent_item_id', $item->id)->with('item')->get();
        $balances = $at(Balance::query()->where('item_id', $item->id))->with('location')->get();
        $variantStock = $variants->isEmpty() ? collect() : $at(Balance::query()->whereIn('item_id', $variants->pluck('item_id')))
            ->selectRaw('item_id, sum(qty) as qty')->groupBy('item_id')->pluck('qty', 'item_id');
        $batchBalances = $at(BatchBalance::query()->where('item_id', $item->id)->where('qty', '<>', 0))->with(['batch', 'location'])->get();
        $serials = $at(Serial::query()->where('item_id', $item->id)->where('status', Serial::IN_STOCK))->with('location')->orderBy('serial_number')->limit(500)->get();
        $rules = $at(ReorderRule::query()->whereNull('archived_at')->where('item_id', $item->id))->with('location')->get()->keyBy('location_id');
        $states = CostState::query()->where('item_id', $item->id)->with('company')->get()
            ->filter(fn (CostState $state) => Values::visible($request, null, $state->company_id));

        return new JsonResponse(['data' => [
            'item' => [...Refs::item($item), 'type' => $item->type, 'base_uom' => Refs::uom($item->baseUom)],
            'settings' => ['tracking' => $settings?->tracking ?? ItemSetting::NONE, 'track_expiry' => (bool) ($settings?->track_expiry ?? false)],
            'variant' => $variant === null ? null : ['parent' => Refs::item($variant->parent), 'attributes' => $variant->attribute_values],
            'variants' => $variants->map(fn (Variant $v) => ['item' => Refs::item($v->item), 'attributes' => $v->attribute_values, 'qty' => Qty::show((string) ($variantStock[$v->item_id] ?? '0'))])->values()->all(),
            'balances' => $balances->map(fn (Balance $b) => [
                'location' => Refs::place($b->location),
                'qty' => Qty::show($b->qty),
                'reorder_level' => Qty::show($rules->get($b->location_id)?->reorder_level),
                'low' => ($rule = $rules->get($b->location_id)) !== null && BigDecimal::of((string) $b->qty)->isLessThanOrEqualTo((string) $rule->reorder_level),
            ])->values()->all(),
            'on_hand' => Qty::show($balances->reduce(fn (BigDecimal $sum, Balance $b) => $sum->plus((string) $b->qty), BigDecimal::zero())),
            'batches' => $batchBalances->groupBy('batch_id')->map(fn ($rows) => [
                ...Refs::batch($rows->first()->batch),
                'expired' => $rows->first()->batch?->expires_on !== null && $rows->first()->batch->expires_on->isPast(),
                'balances' => $rows->map(fn (BatchBalance $b) => ['location' => Refs::place($b->location), 'qty' => Qty::show($b->qty)])->values()->all(),
            ])->sortBy('expires_on')->values()->all(),
            'serials' => $serials->map(fn (Serial $s) => ['id' => $s->id, 'serial_number' => $s->serial_number, 'status' => $s->status, 'location' => Refs::place($s->location)])->all(),
            'reorder_rules' => $rules->map(fn (ReorderRule $r) => ['location' => Refs::place($r->location), 'reorder_level' => Qty::show($r->reorder_level), 'reorder_qty' => Qty::show($r->reorder_qty)])->values()->all(),
            'costs' => $states->map(fn (CostState $s) => [
                'company' => Refs::place($s->company),
                'qty' => Qty::show($s->qty),
                'value' => Values::money($s->value_minor, $s->currency),
            ])->values()->all(),
            'has_movements' => Movement::query()->where('item_id', $item->id)->exists(),
        ]]);
    }

    public function settings(ItemRequest $request, ItemStock $stock, Item $item): JsonResponse
    {
        $settings = $stock->settings($request->item(), (string) $request->validated('tracking'), (bool) $request->validated('track_expiry', false));

        return new JsonResponse(['data' => ['tracking' => $settings->tracking, 'track_expiry' => $settings->track_expiry]]);
    }

    public function variant(ItemRequest $request, ItemStock $stock, Item $item): JsonResponse
    {
        // Attribute names trimmed and lower case, values trimmed.
        $attributes = [];

        foreach ((array) $request->validated('attributes', []) as $name => $value) {
            $attributes[mb_strtolower(trim((string) $name))] = trim((string) $value);
        }

        $variant = $stock->linkVariant($request->item(), $request->validated('parent_item_id'), $attributes);

        return new JsonResponse(['data' => $variant === null ? null : [
            'parent' => Refs::item($variant->parent()->first()),
            'attributes' => $variant->attribute_values,
        ]]);
    }

    public function opening(StoreOpeningStockRequest $request, OpeningStock $opening): JsonResponse
    {
        $movement = $opening->record($request->validated(), $request->user());

        return new JsonResponse(['data' => MovementResource::make($movement->load(MovementList::RELATIONS))->resolve($request)], 201);
    }

    public function valuation(ListValuationRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = CostState::query()->select('inventory_cost_states.*')
            ->join('items', 'items.id', '=', 'inventory_cost_states.item_id')
            ->with(ValuationList::RELATIONS)
            ->where(fn (Builder $q) => $q->where('inventory_cost_states.qty', '<>', 0)->orWhere('inventory_cost_states.value_minor', '<>', 0));
        $request->applyCompanies($query);

        if ($request->filled('category')) {
            $query->where('items.category_id', $request->validated('category'));
        }

        $request->applySearch($query, ['item' => 'items.name', 'code' => 'items.code']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        $page = $query->paginate($request->perPage())->withQueryString();

        return ValuationResource::collection($page)->additional(['meta' => ['totals' => self::totals($request)]]);
    }

    /** INV-20: the value of everything the reader values, per currency. */
    private static function totals(ListValuationRequest $request): array
    {
        $query = CostState::query()->join('items', 'items.id', '=', 'inventory_cost_states.item_id');
        $request->applyCompanies($query);

        if ($request->filled('category')) {
            $query->where('items.category_id', $request->validated('category'));
        }

        return $query->selectRaw('inventory_cost_states.currency, sum(inventory_cost_states.value_minor) as value')
            ->groupBy('inventory_cost_states.currency')->orderBy('inventory_cost_states.currency')->get()
            ->map(fn ($row) => Values::money((string) $row->value, $row->currency))->all();
    }

    /** @return array<string, Builder> the active rule's level and quantity of the row's place */
    public static function ruleColumns(string $table): array
    {
        $rule = fn (string $column) => ReorderRule::query()->select($column)
            ->whereColumn('inventory_reorder_rules.item_id', "{$table}.item_id")
            ->whereColumn('inventory_reorder_rules.location_id', "{$table}.location_id")
            ->whereNull('inventory_reorder_rules.archived_at')->limit(1);

        return ['reorder_level' => $rule('reorder_level'), 'reorder_qty' => $rule('reorder_qty')];
    }

    /** @return array<string, Builder> the company's cost state of the row's item */
    public static function costColumns(string $table): array
    {
        $cost = fn (string $column) => CostState::query()->select($column)
            ->whereColumn('inventory_cost_states.company_id', "{$table}.company_id")
            ->whereColumn('inventory_cost_states.item_id', "{$table}.item_id")->limit(1);

        return [
            'cost_qty' => $cost('qty'), 'cost_value' => $cost('value_minor'), 'cost_currency' => $cost('currency'),
            'last_cost_minor' => $cost('last_cost_minor'), 'last_cost_qty' => $cost('last_cost_qty'),
        ];
    }
}
