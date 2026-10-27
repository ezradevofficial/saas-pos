<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Exports\ListExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hotel\Http\Lists\StayList;
use Modules\Hotel\Http\Requests\OccupancyReportRequest;
use Modules\Hotel\Http\Requests\StayReportRequest;
use Modules\Hotel\Http\Resources\StayResource;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Reports\OccupancyReport;
use Modules\Hotel\Support\Properties;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HOT-31: occupancy, ADR and RevPAR, and the arrivals, departures, in-house and no-show lists (the folio balances and housekeeping reports are the folio and task lists). */
class ReportController
{
    use RespondsWithLists;

    public function __construct(private readonly OccupancyReport $occupancy, private readonly Properties $properties) {}

    public function occupancy(OccupancyReportRequest $request): JsonResponse
    {
        $report = $this->occupancy->between($request->validated('branch_id'), CarbonImmutable::parse($request->validated('from')), CarbonImmutable::parse($request->validated('to')));

        return response()->json(['data' => $report]);
    }

    public function stays(StayReportRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $branch = $request->validated('branch');
        $date = $request->validated('date') ?? $this->properties->businessDate($branch);
        $query = Stay::query()->with(StayList::RELATIONS)->where('branch_id', $branch);

        match ($request->kind()) {
            'arrivals' => $query->where('arrival_on', $date)->whereIn('status', [Stay::RESERVED, Stay::CHECKED_IN, Stay::CHECKED_OUT]),
            'departures' => $query->where('departure_on', $date)->whereIn('status', [Stay::CHECKED_IN, Stay::CHECKED_OUT]),
            'in-house' => $query->where('status', Stay::CHECKED_IN)->where('arrival_on', '<=', $date),
            default => $query->where('status', Stay::NO_SHOW)->where('arrival_on', $date),
        };

        return $this->listed($request, $query, StayResource::class, $export);
    }
}
