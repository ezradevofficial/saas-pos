<?php

namespace Modules\POS\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\POS\Http\Requests\SalesInsightsRequest;
use Modules\POS\Insights\SalesInsights;

/** TEN-07: the consolidated sales dashboard's figures (SalesInsights). */
class InsightsController
{
    public function __invoke(SalesInsightsRequest $request, SalesInsights $insights): JsonResponse
    {
        return response()->json(['data' => $insights->for($request->user(), $request->validated())]);
    }
}
