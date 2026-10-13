<?php

namespace App\Core\Limits\Http\Controllers;

use App\Core\Limits\Http\Requests\UsageRequest;
use App\Core\Limits\UsageLimits;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * LIM-01, LIM-02: GET usage, for the Owner's over-limit banner and the
 * Usage card: each unit's count against the plan, the open overages with
 * the days left in their window, the extra monthly cost and the upgrade
 * link.
 */
class UsageController
{
    public function __invoke(UsageRequest $request, UsageLimits $limits): JsonResponse
    {
        return response()->json(['data' => $limits->status(CarbonImmutable::now())]);
    }
}
