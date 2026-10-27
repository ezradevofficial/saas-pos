<?php

namespace Modules\Hotel\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Hotel\Availability\Availability;
use Modules\Hotel\Http\Requests\AvailabilityRequest;

/** HOT-11: the availability calendar and grid of a property: room types by night, and rooms by the stays holding them. */
class AvailabilityController
{
    public function __invoke(AvailabilityRequest $request, Availability $availability): JsonResponse
    {
        return response()->json(['data' => $availability->grid(
            $request->validated('branch_id'), CarbonImmutable::parse($request->validated('from')), CarbonImmutable::parse($request->validated('to')),
        )]);
    }
}
