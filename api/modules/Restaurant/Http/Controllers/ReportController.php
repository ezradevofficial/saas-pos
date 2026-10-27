<?php

namespace Modules\Restaurant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Restaurant\Http\Requests\ReportRequest;
use Modules\Restaurant\Reports\Summary;

/** REST-18: covers, spend, turn time, waiters and voids after send. */
class ReportController
{
    public function summary(ReportRequest $request, Summary $summary): JsonResponse
    {
        return response()->json(['data' => $summary->run($request->user(), $request->validated())]);
    }
}
