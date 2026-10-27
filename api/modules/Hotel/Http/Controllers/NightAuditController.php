<?php

namespace Modules\Hotel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Hotel\Http\Requests\ListNightAuditsRequest;
use Modules\Hotel\Http\Requests\RunNightAuditRequest;
use Modules\Hotel\Http\Requests\ShowNightAuditRequest;
use Modules\Hotel\Http\Resources\NightAuditResource;
use Modules\Hotel\Models\NightAuditRun;
use Modules\Hotel\NightAudit\NightAudits;
use Modules\Hotel\Support\Properties;

/** HOT-30: night audits of a property: the history, a manual run (the same idempotent function the schedule runs) and one audit's snapshot. */
class NightAuditController
{
    public function __construct(private readonly NightAudits $audits, private readonly Properties $properties) {}

    public function index(ListNightAuditsRequest $request): JsonResponse
    {
        $branch = $request->validated('branch');
        $query = NightAuditRun::query()->where('branch_id', $branch)->orderByDesc('business_date');

        foreach (['from' => '>=', 'to' => '<='] as $filter => $operator) {
            if ($request->filled($filter)) {
                $query->where('business_date', $operator, $request->validated($filter));
            }
        }

        return response()->json([
            'data' => NightAuditResource::collection($query->limit(100)->get())->toArray($request),
            'meta' => ['branch_id' => $branch, 'business_date' => $this->properties->businessDate($branch)],
        ]);
    }

    /** 201 for an audit made now, 200 for a date already audited (idempotent, HOT-30). */
    public function store(RunNightAuditRequest $request): JsonResponse
    {
        $result = $this->audits->run($request->validated('branch_id'), $request->user(), $request->validated('business_date'));

        return NightAuditResource::make($result['run'])->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    public function show(ShowNightAuditRequest $request, NightAuditRun $hotelAudit): NightAuditResource
    {
        return NightAuditResource::make($hotelAudit);
    }
}
