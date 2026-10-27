<?php

namespace Modules\VanSales\Http\Controllers;

use App\Core\MasterData\Parties\Party;
use Illuminate\Http\JsonResponse;
use Modules\VanSales\Http\Requests\BoundRequest;
use Modules\VanSales\Http\Requests\ViewRequest;
use Modules\VanSales\Http\Resources\Views;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\Support\Places;

/** VAN-06, VAN-24: runs (a van's days) and, for those who may, check-in positions. */
class RunController
{
    public function __construct(private readonly Places $places, private readonly Views $views) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Run::query()->orderByDesc('run_date')->orderByDesc('started_at')->orderBy('id');
        $this->places->filterLocations($query, $request->user(), $request->permission());

        foreach (['company_id', 'branch_id', 'van_id', 'route_id', 'rep_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $request->filled('from') && $query->where('run_date', '>=', $request->input('from'));
        $request->filled('to') && $query->where('run_date', '<=', $request->input('to'));

        $page = $query->paginate($request->perPage())->withQueryString();

        return response()->json([
            'data' => $this->views->runs($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(BoundRequest $request, Run $vsRun): JsonResponse
    {
        return response()->json(['data' => $this->views->runs(collect([$vsRun]), true)[0]]);
    }

    /** VAN-24: positions only with `van_sales.visit.track` at the run's location; never in another list. */
    public function positions(BoundRequest $request, Run $vsRun): JsonResponse
    {
        $visits = Visit::query()->where('run_id', $vsRun->id)->whereNotNull('checkin_lat')->orderBy('checkin_at')->get();
        $names = Party::query()->whereKey($visits->pluck('customer_id'))->pluck('name', 'id');

        return response()->json(['data' => $visits->map(fn (Visit $visit) => [
            'visit_id' => $visit->id, 'customer_name' => $names[$visit->customer_id] ?? null,
            'lat' => (float) $visit->checkin_lat, 'lng' => (float) $visit->checkin_lng, 'accuracy_m' => $visit->checkin_accuracy_m,
            'at' => $visit->checkin_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ])->values()->all()]);
    }
}
