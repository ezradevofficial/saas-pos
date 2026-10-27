<?php

namespace Modules\FuelStation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\FuelStation\Http\Requests\ReportRequest;
use Modules\FuelStation\Reports\Reports;

/** FUEL-34, FUEL-38: the reports, limited to the reader's stations (RBAC-04). */
class ReportController
{
    public function __construct(private readonly Reports $reports) {}

    public function shiftSummary(ReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->shiftSummary($request->user(), $request));
    }

    public function attendantVariance(ReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->attendantVariance($request->user(), $request));
    }

    public function nozzleMeterHistory(ReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->nozzleMeterHistory($request->user(), $request));
    }

    public function tankReconciliation(ReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->tankReconciliation($request->user(), $request));
    }

    public function salesByProduct(ReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->salesByProduct($request->user(), $request));
    }

    public function fleetStatement(ReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->fleetStatement($request->user(), $request));
    }
}
