<?php

namespace Modules\Restaurant\Http\Controllers;

use App\Core\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Restaurant\Http\Requests\ArchiveRequest;
use Modules\Restaurant\Http\Requests\StoreAreaRequest;
use Modules\Restaurant\Http\Requests\StoreTableRequest;
use Modules\Restaurant\Http\Requests\TableStatusRequest;
use Modules\Restaurant\Http\Requests\UpdateAreaRequest;
use Modules\Restaurant\Http\Requests\UpdateTableRequest;
use Modules\Restaurant\Http\Requests\ViewRequest;
use Modules\Restaurant\Http\Resources\Presenters;
use Modules\Restaurant\Models\Area;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\Orders\OrderView;
use Modules\Restaurant\Support\Errors;
use Modules\Restaurant\Support\Places;

/** REST-02: areas and tables of a location, and a table's manual status. */
class FloorController
{
    public function __construct(private readonly Places $places) {}

    public function areas(ViewRequest $request): JsonResponse
    {
        $query = Area::query()->orderBy('sort')->orderBy('name')->orderBy('id');
        $this->places->filterLocations($query, $request->user(), $request->permission());

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }

        if (! $request->boolean('archived')) {
            $query->whereNull('archived_at');
        }

        return response()->json(['data' => $query->get()->map(fn (Area $a) => Presenters::area($a))->all()]);
    }

    public function storeArea(StoreAreaRequest $request): JsonResponse
    {
        $place = $this->places->open((string) $request->input('location_id'));
        $area = Errors::named(fn () => Area::create([...$request->safe()->only(['location_id', 'name', 'sort']), 'company_id' => $place['company_id'], 'branch_id' => $place['branch_id']]));

        return response()->json(['data' => Presenters::area($area->refresh())], 201);
    }

    public function updateArea(UpdateAreaRequest $request, Area $restArea): JsonResponse
    {
        Errors::named(fn () => $restArea->fill($request->validated())->save());

        return response()->json(['data' => Presenters::area($restArea)]);
    }

    public function archiveArea(ArchiveRequest $request, Area $restArea): JsonResponse
    {
        if (Table::query()->where('area_id', $restArea->id)->whereNull('archived_at')->exists()) {
            throw Errors::make(409, 'area_not_empty');
        }

        $restArea->archive();

        return response()->json(['data' => Presenters::area($restArea)]);
    }

    /** Tables of a location with the status shown: occupied or bill while an order is open on them. */
    public function tables(ViewRequest $request, OrderView $view): JsonResponse
    {
        $query = Table::query()->orderBy('sort')->orderBy('name')->orderBy('id');
        $this->places->filterLocations($query, $request->user(), $request->permission());

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }

        if (! $request->boolean('archived')) {
            $query->whereNull('archived_at');
        }

        $tables = $query->get();
        $orders = Order::query()->whereIn('table_id', $tables->pluck('id'))->where('status', 'open')->orderBy('opened_at')->with('lines')->get()->groupBy('table_id');
        $names = User::query()->whereKey($orders->flatten()->pluck('waiter_id')->unique()->all())->pluck('name', 'id');

        return response()->json(['data' => $tables->map(function (Table $table) use ($orders, $view, $names) {
            $order = ($orders[$table->id] ?? collect())->first();

            return Presenters::table($table, $order === null ? null : [
                'id' => $order->id, 'kind' => $order->kind, 'waiter_id' => $order->waiter_id, 'waiter_name' => $names[$order->waiter_id] ?? null,
                'covers' => (int) $order->covers, 'opened_at' => $order->opened_at->toIso8601String(),
                'total_minor' => $view->bill($order, $order->lines)['total_minor'], 'currency' => $order->currency, 'bill_requested' => $order->bill_requested_at !== null,
            ]);
        })->all()]);
    }

    public function storeTable(StoreTableRequest $request): JsonResponse
    {
        $place = $this->places->open((string) $request->input('location_id'));
        $area = Area::query()->find($request->input('area_id'));

        if ($area === null || $area->location_id !== $request->input('location_id') || $area->archived_at !== null) {
            throw Errors::make(422, 'area_unknown', field: 'area_id');
        }

        $table = Errors::named(fn () => Table::create([...$request->validated(), 'company_id' => $place['company_id'], 'branch_id' => $place['branch_id']]));

        return response()->json(['data' => Presenters::table($table->refresh())], 201);
    }

    public function updateTable(UpdateTableRequest $request, Table $restTable): JsonResponse
    {
        if ($request->filled('area_id')) {
            $area = Area::query()->find($request->input('area_id'));

            if ($area === null || $area->location_id !== $restTable->location_id || $area->archived_at !== null) {
                throw Errors::make(422, 'area_unknown', field: 'area_id');
            }
        }

        Errors::named(fn () => $restTable->fill($request->validated())->save());

        return response()->json(['data' => Presenters::table($restTable)]);
    }

    public function tableStatus(TableStatusRequest $request, Table $restTable): JsonResponse
    {
        $restTable->manual_status = $request->validated('status');
        $restTable->save();

        return response()->json(['data' => Presenters::table($restTable)]);
    }

    public function archiveTable(ArchiveRequest $request, Table $restTable): JsonResponse
    {
        if (Order::query()->where('table_id', $restTable->id)->where('status', 'open')->exists()) {
            throw Errors::make(409, 'table_in_use');
        }

        $restTable->archive();

        return response()->json(['data' => Presenters::table($restTable)]);
    }
}
