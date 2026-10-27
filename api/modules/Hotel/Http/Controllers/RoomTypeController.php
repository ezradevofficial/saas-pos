<?php

namespace Modules\Hotel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hotel\Http\Requests\ArchiveRoomTypeRequest;
use Modules\Hotel\Http\Requests\ListRoomTypesRequest;
use Modules\Hotel\Http\Requests\StoreRoomTypeRequest;
use Modules\Hotel\Http\Requests\UpdateRoomTypeRequest;
use Modules\Hotel\Http\Resources\RoomTypeResource;
use Modules\Hotel\Models\RoomType;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Support\Access;
use Modules\Hotel\Support\HotelError;

/** HOT-03: room types of the companies the reader reaches. Archived, never deleted (TEN-06); creates, updates and archiving are audited by the model. */
class RoomTypeController
{
    public function __construct(private readonly Access $access) {}

    public function index(ListRoomTypesRequest $request): AnonymousResourceCollection
    {
        $query = $this->access->scopeCompanies(RoomType::query()->withCount(['rooms' => fn ($q) => $q->whereNull('archived_at')]), $request->user(), 'hotel.room.view')
            ->orderBy('sort')->orderBy('code');
        $status = $request->validated('status', 'active');
        $status === 'all' ? null : $query->{$status === 'archived' ? 'whereNotNull' : 'whereNull'}('archived_at');

        if ($request->filled('company')) {
            $query->where('company_id', $request->validated('company'));
        }

        if ($request->filled('search')) {
            $like = '%'.addcslashes((string) $request->validated('search'), '\\%_').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like));
        }

        return RoomTypeResource::collection($query->get());
    }

    public function store(StoreRoomTypeRequest $request): JsonResponse
    {
        $type = RoomType::create($request->validated());

        return RoomTypeResource::make($type->refresh())->response()->setStatusCode(201);
    }

    public function update(UpdateRoomTypeRequest $request, RoomType $hotelRoomType): RoomTypeResource
    {
        $hotelRoomType->fill($request->validated())->save();

        return RoomTypeResource::make($hotelRoomType->refresh());
    }

    public function archive(ArchiveRoomTypeRequest $request, RoomType $hotelRoomType): RoomTypeResource
    {
        if ($hotelRoomType->rooms()->whereNull('archived_at')->exists() || Stay::query()->where('room_type_id', $hotelRoomType->id)->whereIn('status', Stay::HOLDING)->exists()) {
            throw HotelError::make(409, 'room_type_in_use');
        }

        $hotelRoomType->archive();

        return RoomTypeResource::make($hotelRoomType->refresh());
    }
}
