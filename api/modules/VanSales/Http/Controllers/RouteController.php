<?php

namespace Modules\VanSales\Http\Controllers;

use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\VanSales\Http\Requests\BoundRequest;
use Modules\VanSales\Http\Requests\StopsRequest;
use Modules\VanSales\Http\Requests\StoreRouteRequest;
use Modules\VanSales\Http\Requests\UpdateRouteRequest;
use Modules\VanSales\Http\Requests\ViewRequest;
use Modules\VanSales\Http\Resources\Views;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Stop;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Runs\Plan;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Support\Places;

/** VAN-03, VAN-04, VAN-05: routes, their stops and the plan of a day. */
class RouteController
{
    public function __construct(private readonly Places $places, private readonly Views $views) {}

    public function index(ViewRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->views->routes($this->visible($request)->get())]);
    }

    public function show(BoundRequest $request, Route $vsRoute): JsonResponse
    {
        return response()->json(['data' => $this->views->routes(collect([$vsRoute]), true)[0]]);
    }

    public function store(StoreRouteRequest $request): JsonResponse
    {
        $branch = Branch::query()->findOrFail($request->input('branch_id'));
        $this->assertCompany($request->input('company_id'), $branch->company_id);
        $this->assertVan($request->input('van_id'), $branch->company_id);

        $route = Errors::named(fn () => Route::create([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'van_id' => $request->input('van_id'),
            'name' => $request->input('name'), 'visit_days' => $this->days($request->input('visit_days')),
        ]));

        return response()->json(['data' => $this->views->routes(collect([$route->refresh()]))[0]], 201);
    }

    public function update(UpdateRouteRequest $request, Route $vsRoute): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('van_id', $data)) {
            $this->assertVan($data['van_id'], $vsRoute->company_id);
        }

        if (array_key_exists('visit_days', $data)) {
            $data['visit_days'] = $this->days($data['visit_days']);
        }

        Errors::named(fn () => $vsRoute->fill($data)->save());

        return response()->json(['data' => $this->views->routes(collect([$vsRoute->refresh()]))[0]]);
    }

    public function archive(BoundRequest $request, Route $vsRoute): JsonResponse
    {
        if (Run::query()->where('route_id', $vsRoute->id)->where('status', 'open')->exists()) {
            throw Errors::make(409, 'route_has_open_run');
        }

        $vsRoute->archive();

        return response()->json(['data' => $this->views->routes(collect([$vsRoute->refresh()]))[0]]);
    }

    /** VAN-04: the list replaces the stops, in order; a stop left out is archived and keeps its visits. */
    public function stops(StopsRequest $request, Route $vsRoute): JsonResponse
    {
        $rows = array_values($request->validated('stops'));
        $parties = Party::query()->whereKey(array_column($rows, 'customer_id'))->get()->keyBy('id');

        foreach ($rows as $index => $row) {
            if (! in_array('customer', (array) ($parties->get($row['customer_id'])?->roles ?? []), true)) {
                throw Errors::make(422, 'customer_unknown', field: "stops.{$index}.customer_id");
            }
        }

        Errors::transaction(function () use ($vsRoute, $rows) {
            $active = Stop::query()->where('route_id', $vsRoute->id)->whereNull('archived_at')->lockForUpdate()->get()->keyBy('customer_id');
            $keep = [];

            foreach ($rows as $index => $row) {
                $stop = $active->get($row['customer_id']) ?? new Stop(['company_id' => $vsRoute->company_id, 'route_id' => $vsRoute->id, 'customer_id' => $row['customer_id']]);
                $stop->fill(['position' => $index + 1, 'visit_days' => isset($row['visit_days']) ? $this->days($row['visit_days']) : null])->save();
                $keep[] = $stop->id;
            }

            $active->reject(fn (Stop $stop) => in_array($stop->id, $keep, true))->each->archive();
        });

        return response()->json(['data' => $this->views->routes(collect([$vsRoute->refresh()]), true)[0]]);
    }

    public function plan(ViewRequest $request): JsonResponse
    {
        $date = (string) $request->input('date', CarbonImmutable::now()->toDateString());
        $routes = $this->visible($request)->when($request->filled('route_id'), fn ($q) => $q->whereKey($request->input('route_id')))->get();

        return response()->json(['data' => ['date' => $date, 'routes' => (new Plan)->forDate($routes, $date)]]);
    }

    private function visible(ViewRequest $request)
    {
        $query = Route::query()->orderBy('name')->orderBy('id');
        $this->places->filterBranches($query, $request->user(), $request->permission());

        foreach (['company_id', 'branch_id', 'van_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $request->boolean('archived') ? $query : $query->whereNull('archived_at');
    }

    /** @param list<int|string> $days @return list<int> */
    private function days(array $days): array
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);

        return $days;
    }

    private function assertCompany(?string $given, string $companyId): void
    {
        if ($given !== null && $given !== $companyId) {
            throw Errors::make(422, 'company_mismatch', field: 'company_id');
        }
    }

    private function assertVan(?string $vanId, string $companyId): void
    {
        if ($vanId !== null && ! Van::query()->whereKey($vanId)->where('company_id', $companyId)->whereNull('archived_at')->exists()) {
            throw Errors::make(422, 'van_unknown', field: 'van_id');
        }
    }
}
