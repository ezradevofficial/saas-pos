<?php

namespace Modules\Stores\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Stores\Http\Lists\ConsumptionList;
use Modules\Stores\Http\Lists\LedgerList;
use Modules\Stores\Http\Lists\StockList;
use Modules\Stores\Http\Requests\ConsumptionRequest;
use Modules\Stores\Http\Requests\ListLedgerRequest;
use Modules\Stores\Http\Requests\ListStockRequest;
use Modules\Stores\Http\Requests\StatusRequest;
use Modules\Stores\Http\Requests\StockEntryRequest;
use Modules\Stores\Http\Resources\ConsumptionResource;
use Modules\Stores\Http\Resources\MovementResource;
use Modules\Stores\Http\Resources\StockResource;
use Modules\Stores\Http\Resources\Values;
use Modules\Stores\Models\Movement;
use Modules\Stores\Stock\StockEntries;
use Modules\Stores\Stock\StoreLedger;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * STR-07 to STR-12: the store ledger, the own ledger's stock on hand and
 * entries, consumption reports and which ledger holds the quantities, at
 * the reader's stores (RBAC-04), values for value holders only (RBAC-05).
 */
class LedgerController
{
    public function ledger(ListLedgerRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Movement::query()->select('stores_movements.*')
            ->join('items', 'items.id', '=', 'stores_movements.item_id')
            ->with(LedgerList::RELATIONS);
        $request->applyFilters($query, dateColumn: 'occurred_at', items: true);

        if ($request->filled('kind')) {
            $query->where('stores_movements.kind', $request->validated('kind'));
        }

        $request->applySearch($query, ['item' => 'items.name', 'code' => 'items.code', 'reference' => 'stores_movements.reference']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return MovementResource::collection($query->paginate($request->perPage())->withQueryString())
            ->additional(['meta' => ['ledger' => app(StoreLedger::class)->current()]]);
    }

    public function stock(ListStockRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Movement::query()->select('stores_movements.*')
            ->join('items', 'items.id', '=', 'stores_movements.item_id')
            ->where('stores_movements.ledger', Movement::LEDGER_STORES)
            ->whereRaw("stores_movements.seq = (select max(m.seq) from stores_movements m where m.location_id = stores_movements.location_id and m.item_id = stores_movements.item_id and m.ledger = 'stores')")
            ->with(StockList::RELATIONS);
        $request->applyFilters($query, items: true);
        $request->applySearch($query, ['item' => 'items.name', 'code' => 'items.code']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return StockResource::collection($query->paginate($request->perPage())->withQueryString())
            ->additional(['meta' => ['ledger' => app(StoreLedger::class)->current()]]);
    }

    public function entries(StockEntryRequest $request, StockEntries $entries): JsonResponse
    {
        $movements = $entries->record($request->validated(), $request->user());

        return MovementResource::collection(Movement::query()->whereKey(array_map(fn (Movement $m) => $m->id, $movements))->orderBy('seq')->with(LedgerList::RELATIONS)->get())
            ->response()->setStatusCode(201);
    }

    public function consumption(ConsumptionRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $this->consumptionQuery($request);
        $sort = $request->sort() ?: (new ConsumptionList)->defaultSort();
        (new ConsumptionList)->sorts()[ltrim($sort, '-')]->apply($query, str_starts_with($sort, '-') ? 'desc' : 'asc');
        $query->orderBy('stores_movements.item_id')->orderBy('stores_movements.company_id');

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ConsumptionResource::collection($query->paginate($request->perPage())->withQueryString())
            ->additional(['meta' => ['group' => $request->group(), 'totals' => $this->totals($request)]]);
    }

    public function status(StatusRequest $request, StoreLedger $ledger): JsonResponse
    {
        return response()->json(['data' => ['ledger' => $ledger->current(), 'inventory_active' => $ledger->current() === Movement::LEDGER_INVENTORY]]);
    }

    /** STR-12: issues and returns at the reader's stores, one row per group, item and company. */
    private function consumptionQuery(ConsumptionRequest $request): Builder
    {
        $group = $request->group();
        $key = match ($group) {
            'department' => 'stores_movements.department_id::text',
            'cost_centre' => 'stores_movements.cost_centre_id::text',
            'period' => "to_char(stores_movements.occurred_at at time zone coalesce(companies.timezone, 'UTC'), 'YYYY-MM')",
            default => null,
        };

        $query = Movement::query()
            ->join('items', 'items.id', '=', 'stores_movements.item_id')
            ->join('companies', 'companies.id', '=', 'stores_movements.company_id')
            ->whereIn('stores_movements.kind', [Movement::ISSUE, Movement::RETURN])
            ->with(ConsumptionList::RELATIONS)
            ->selectRaw(($key ?? 'null').' as group_key')
            ->selectRaw("'{$group}' as group_by")
            ->selectRaw(<<<'SQL'
                stores_movements.item_id, stores_movements.company_id,
                coalesce(sum(case when stores_movements.kind = 'issue' then -stores_movements.qty end), 0) as issued_qty,
                coalesce(sum(case when stores_movements.kind = 'return' then stores_movements.qty end), 0) as returned_qty,
                coalesce(sum(case when stores_movements.kind = 'issue' then -stores_movements.value_minor end), 0) as issued_value,
                coalesce(sum(case when stores_movements.kind = 'return' then stores_movements.value_minor end), 0) as returned_value,
                count(*) filter (where stores_movements.value_minor is null) as unvalued,
                max(stores_movements.currency) as value_currency,
                string_agg(distinct stores_movements.location_id::text, ',') as locations
                SQL);
        $request->applyFilters($query, dateColumn: 'occurred_at', items: true);
        $request->applySearch($query, ['item' => 'items.name', 'code' => 'items.code']);

        return $key === null
            ? $query->groupBy('stores_movements.item_id', 'stores_movements.company_id')
            : $query->groupByRaw($key.', stores_movements.item_id, stores_movements.company_id');
    }

    /**
     * STR-12: the net value consumed per currency, over the rows the reader
     * may value (RBAC-05), valued movements only.
     *
     * @return list<array{amount_minor: string, currency: string}>
     */
    private function totals(ConsumptionRequest $request): array
    {
        if (! Values::anywhere($request)) {
            return [];
        }

        $query = Movement::query()
            ->join('items', 'items.id', '=', 'stores_movements.item_id')
            ->whereIn('stores_movements.kind', [Movement::ISSUE, Movement::RETURN])
            ->whereNotNull('stores_movements.value_minor');
        $request->applyFilters($query, dateColumn: 'occurred_at', items: true);
        $request->applySearch($query, ['item' => 'items.name', 'code' => 'items.code']);
        $values = app(ScopeResolver::class)->visibleIds($request->user(), Values::PERMISSION);

        if (! $values->all) {
            $query->whereIn('stores_movements.location_id', $values->idsFor(Scope::LOCATION));
        }

        return $query->selectRaw('stores_movements.currency, -sum(stores_movements.value_minor) as value')
            ->groupBy('stores_movements.currency')->orderBy('stores_movements.currency')->toBase()->get()
            ->map(fn ($row) => Values::money((string) $row->value, $row->currency))->all();
    }
}
