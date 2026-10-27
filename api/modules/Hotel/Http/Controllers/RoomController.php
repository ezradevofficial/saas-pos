<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hotel\Housekeeping\Housekeeping;
use Modules\Hotel\Http\Requests\ArchiveRoomRequest;
use Modules\Hotel\Http\Requests\ListRoomsRequest;
use Modules\Hotel\Http\Requests\RoomStatusRequest;
use Modules\Hotel\Http\Requests\StoreRoomRequest;
use Modules\Hotel\Http\Requests\UpdateRoomRequest;
use Modules\Hotel\Http\Resources\RoomResource;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Support\Access;
use Modules\Hotel\Support\HotelError;

/** HOT-04, HOT-05: rooms of the properties the reader reaches, and their status. Changes are audited by the model (`hotel.room.*`). */
class RoomController
{
    public function __construct(private readonly Access $access, private readonly Housekeeping $housekeeping) {}

    public function index(ListRoomsRequest $request): AnonymousResourceCollection
    {
        $query = $this->access->scopeBranches(Room::query()->with(['roomType:id,code,name', 'branch:id,name']), $request->user(), 'hotel.room.view')
            ->orderBy('floor')->orderBy('number');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        foreach (['branch' => 'branch_id', 'room_type' => 'room_type_id', 'status' => 'status', 'floor' => 'floor'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        if ($request->filled('search')) {
            $query->where('number', 'ilike', '%'.addcslashes((string) $request->validated('search'), '\\%_').'%');
        }

        return RoomResource::collection($query->get());
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $branch = Branch::query()->findOrFail($request->validated('branch_id'));
        $room = Room::create([...$request->validated(), 'company_id' => $branch->company_id, 'status' => Room::VACANT_CLEAN, 'status_changed_at' => now()]);

        return RoomResource::make($room->refresh()->load(['roomType:id,code,name', 'branch:id,name']))->response()->setStatusCode(201);
    }

    public function update(UpdateRoomRequest $request, Room $hotelRoom): RoomResource
    {
        $hotelRoom->fill($request->validated())->save();

        return RoomResource::make($hotelRoom->refresh()->load(['roomType:id,code,name', 'branch:id,name']));
    }

    public function archive(ArchiveRoomRequest $request, Room $hotelRoom): RoomResource
    {
        if ($hotelRoom->status === Room::OCCUPIED || Stay::query()->where('room_id', $hotelRoom->id)->whereIn('status', Stay::HOLDING)->exists()) {
            throw HotelError::make(409, 'room_in_use');
        }

        $hotelRoom->archive();

        return RoomResource::make($hotelRoom->refresh());
    }

    public function status(RoomStatusRequest $request, Room $hotelRoom): RoomResource
    {
        $room = $this->housekeeping->setStatus(
            $hotelRoom, $request->validated('status'), $request->user(),
            ['reason' => $request->validated('reason'), 'from' => $request->validated('from'), 'until' => $request->validated('until')],
            byHousekeeper: $request->byHousekeeper(),
        );

        return RoomResource::make($room->load(['roomType:id,code,name', 'branch:id,name']));
    }
}
