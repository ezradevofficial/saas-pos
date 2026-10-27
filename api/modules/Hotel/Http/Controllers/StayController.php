<?php

namespace Modules\Hotel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Hotel\Http\Requests\AssignRoomRequest;
use Modules\Hotel\Http\Requests\CancelStayRequest;
use Modules\Hotel\Http\Requests\CheckInRequest;
use Modules\Hotel\Http\Requests\CheckOutRequest;
use Modules\Hotel\Http\Requests\MoveRoomRequest;
use Modules\Hotel\Http\Requests\NoShowStayRequest;
use Modules\Hotel\Http\Requests\UpdateStayRequest;
use Modules\Hotel\Http\Resources\FolioResource;
use Modules\Hotel\Http\Resources\StayResource;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Reservations\Reservations;
use Modules\Hotel\Stays\Stays;

/** HOT-15, HOT-17 to HOT-19, HOT-37: what happens to a stay: changes, a room, check-in and out, a move, cancellation and no-show. */
class StayController
{
    private const WITH = ['reservation', 'guest', 'roomType', 'ratePlan', 'room', 'folio'];

    public function __construct(private readonly Reservations $reservations, private readonly Stays $stays) {}

    public function update(UpdateStayRequest $request, Stay $hotelStay): StayResource
    {
        return $this->show($this->reservations->modify($hotelStay, $request->validated(), $request->user()));
    }

    public function assignRoom(AssignRoomRequest $request, Stay $hotelStay): StayResource
    {
        return $this->show($this->stays->assignRoom($hotelStay, $request->validated('room_id'), $request->user()));
    }

    public function checkIn(CheckInRequest $request, Stay $hotelStay): StayResource
    {
        return $this->show($this->stays->checkIn($hotelStay, $request->validated(), $request->user(), early: $request->early()));
    }

    public function checkOut(CheckOutRequest $request, Stay $hotelStay): JsonResponse
    {
        $result = $this->stays->checkOut($hotelStay, $request->validated(), $request->user(), allowBalance: $request->boolean('leave_balance'));

        return response()->json(['data' => [
            'stay' => StayResource::make($result['stay']->load(self::WITH))->detail()->toArray($request),
            'folio' => FolioResource::make($result['folio']->load('lines'))->detail()->toArray($request),
        ]]);
    }

    public function moveRoom(MoveRoomRequest $request, Stay $hotelStay): StayResource
    {
        return $this->show($this->stays->moveRoom($hotelStay, $request->validated('room_id'), $request->validated('reason'), $request->user()));
    }

    public function cancel(CancelStayRequest $request, Stay $hotelStay): StayResource
    {
        return $this->show($this->reservations->cancel($hotelStay, $request->validated('reason'), $request->boolean('waive_charge'), $request->user()));
    }

    public function noShow(NoShowStayRequest $request, Stay $hotelStay): StayResource
    {
        return $this->show($this->reservations->noShow($hotelStay, $request->validated('reason'), $request->boolean('waive_charge'), $request->user()));
    }

    private function show(Stay $stay): StayResource
    {
        return StayResource::make($stay->load(self::WITH))->detail();
    }
}
