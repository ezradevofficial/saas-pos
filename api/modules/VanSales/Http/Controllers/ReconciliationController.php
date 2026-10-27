<?php

namespace Modules\VanSales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\VanSales\Http\Requests\BoundRequest;
use Modules\VanSales\Http\Requests\UnloadReceiveRequest;
use Modules\VanSales\Http\Requests\ViewRequest;
use Modules\VanSales\Http\Resources\Views;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Reconciliation\Unloads;
use Modules\VanSales\Support\Places;

/** VAN-18 to VAN-23: the day's counts and their reconciliation (decided in the approvals inbox, WF-01). */
class ReconciliationController
{
    public function __construct(private readonly Places $places, private readonly Views $views, private readonly Unloads $unloads) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Count::query()->orderByRaw("case status when 'pending' then 0 else 1 end")->orderByDesc('submitted_at')->orderBy('id');
        $this->places->filterBranches($query, $request->user(), $request->permission());

        foreach (['company_id', 'branch_id', 'van_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $page = $query->paginate($request->perPage())->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Count $count) => $this->views->reconciliation($count, false))->values()->all(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(BoundRequest $request, Count $vsCount): JsonResponse
    {
        return response()->json(['data' => $this->views->reconciliation($vsCount)]);
    }

    public function unloadReceive(UnloadReceiveRequest $request, Count $vsCount): JsonResponse
    {
        $this->unloads->receive($vsCount, $request->user(), $request->validated('lines'));

        return response()->json(['data' => $this->views->reconciliation($vsCount->refresh())]);
    }
}
