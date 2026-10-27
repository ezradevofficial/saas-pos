<?php

namespace Modules\VanSales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\VanSales\Http\Requests\BoundRequest;
use Modules\VanSales\Http\Requests\CancelLoadRequest;
use Modules\VanSales\Http\Requests\ReleaseLoadRequest;
use Modules\VanSales\Http\Requests\StoreLoadRequest;
use Modules\VanSales\Http\Requests\ViewRequest;
use Modules\VanSales\Http\Resources\Views;
use Modules\VanSales\Loads\Loads;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Support\Places;

/** VAN-07: load sheets (Inventory transfers from a warehouse to a van). */
class LoadController
{
    public function __construct(private readonly Places $places, private readonly Views $views, private readonly Loads $loads) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Load::query()->orderByDesc('created_at')->orderByDesc('id')->limit(200);
        $this->places->filterLocations($query, $request->user(), $request->permission(), 'to_location_id');

        foreach (['company_id', 'branch_id', 'van_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $rows = $this->views->loads($query->get());

        if ($request->filled('status')) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['status'] === $request->input('status')));
        }

        return response()->json(['data' => $rows]);
    }

    public function show(BoundRequest $request, Load $vsLoad): JsonResponse
    {
        return response()->json(['data' => $this->views->loads(collect([$vsLoad]))[0]]);
    }

    public function store(StoreLoadRequest $request): JsonResponse
    {
        $load = $this->loads->request($request->validated(), $request->user());

        return response()->json(['data' => $this->views->loads(collect([$load]))[0]], 201);
    }

    public function release(ReleaseLoadRequest $request, Load $vsLoad): JsonResponse
    {
        return response()->json(['data' => $this->views->loads(collect([$this->loads->release($vsLoad, $request->user())]))[0]]);
    }

    public function cancel(CancelLoadRequest $request, Load $vsLoad): JsonResponse
    {
        return response()->json(['data' => $this->views->loads(collect([$this->loads->cancel($vsLoad, $request->user(), (string) $request->validated('reason'))]))[0]]);
    }
}
