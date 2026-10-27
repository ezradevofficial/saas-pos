<?php

namespace Modules\Salon\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Salon\Booking\PublicBooking;
use Modules\Salon\Booking\ResolveBooking;
use Modules\Salon\Http\Requests\PublicBookRequest;
use Modules\Salon\Http\Requests\PublicMenuRequest;
use Modules\Salon\Http\Requests\PublicOwnedRequest;
use Modules\Salon\Http\Requests\PublicSlotsRequest;

/**
 * SAL-15, SAL-26: the public booking page. No token; the branch is found by its code (ResolveBooking), every route is
 * rate-limited, and nothing here reads or returns anything about a person other than the booking asked for.
 */
class PublicBookingController
{
    public function __construct(private readonly PublicBooking $booking) {}

    public function show(PublicMenuRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->booking->menu(ResolveBooking::branch($request))]);
    }

    public function slots(PublicSlotsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->booking->slots(ResolveBooking::branch($request), array_values((array) $request->input('service_ids')), (string) $request->input('date'), $request->input('staff_id'))]);
    }

    public function store(PublicBookRequest $request): JsonResponse
    {
        $data = $this->booking->book(ResolveBooking::branch($request), $request->validated(), $request->bearerToken(), $request->header('Idempotency-Key'));

        return response()->json(['data' => $data], 201);
    }

    public function pay(PublicOwnedRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->booking->payDeposit(ResolveBooking::branch($request), (string) $request->route('number'), (string) $request->input('phone'))]);
    }

    public function status(PublicOwnedRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->booking->status(ResolveBooking::branch($request), (string) $request->route('number'), (string) $request->input('phone'))]);
    }
}
