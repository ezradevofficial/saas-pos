<?php

namespace Modules\VanSales\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\VanSales\Http\Requests\ViewRequest;
use Modules\VanSales\Reports\Summary;

/** VAN-25: reports. */
class ReportController
{
    public function __construct(private readonly Summary $summary) {}

    public function summary(ViewRequest $request): JsonResponse
    {
        $to = (string) $request->input('to', CarbonImmutable::now()->toDateString());
        $from = (string) $request->input('from', CarbonImmutable::parse($to)->subDays(6)->toDateString());

        return response()->json(['data' => $this->summary->summary($request->user(), $request->permission(), [
            'from' => $from, 'to' => $to, ...$request->only(['company_id', 'branch_id', 'route_id', 'rep_id', 'van_id']),
        ])]);
    }

    public function vanStock(ViewRequest $request): JsonResponse
    {
        return response()->json($this->summary->vanStock($request->user(), $request->permission()));
    }
}
