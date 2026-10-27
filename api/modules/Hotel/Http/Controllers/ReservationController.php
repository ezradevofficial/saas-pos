<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hotel\Http\Lists\StayList;
use Modules\Hotel\Http\Requests\ListReservationsRequest;
use Modules\Hotel\Http\Requests\ShowReservationRequest;
use Modules\Hotel\Http\Requests\StoreReservationRequest;
use Modules\Hotel\Http\Requests\WalkInRequest;
use Modules\Hotel\Http\Resources\ReservationResource;
use Modules\Hotel\Http\Resources\StayResource;
use Modules\Hotel\Models\Reservation;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Reservations\Reservations;
use Modules\Hotel\Stays\WalkIns;
use Modules\Hotel\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HOT-12: bookings. The list shows stays with their booking number; a booking shows all its stays and folios. */
class ReservationController
{
    use RespondsWithLists;

    public function __construct(private readonly Access $access, private readonly Reservations $reservations, private readonly WalkIns $walkIns) {}

    public function index(ListReservationsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $this->access->scopeBranches(Stay::query()->with(StayList::RELATIONS), $request->user(), 'hotel.reservation.view');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->validated('branch'));
        }

        $status = $request->validated('status', 'open');
        $status === 'open' ? $query->whereIn('status', Stay::HOLDING) : ($status === 'all' ? null : $query->where('status', $status));

        if ($request->filled('kind')) {
            $query->whereIn('reservation_id', fn ($q) => $q->select('id')->from('hotel_reservations')->where('kind', $request->validated('kind')));
        }

        if ($request->filled('room_type')) {
            $query->where('room_type_id', $request->validated('room_type'));
        }

        foreach (['arrival_from' => ['arrival_on', '>='], 'arrival_to' => ['arrival_on', '<=']] as $filter => [$column, $operator]) {
            if ($request->filled($filter)) {
                $query->where($column, $operator, $request->validated($filter));
            }
        }

        if ($request->filled('search')) {
            $like = '%'.addcslashes((string) $request->validated('search'), '\\%_').'%';
            $query->where(fn (Builder $q) => $q->whereIn('reservation_id', fn ($r) => $r->select('id')->from('hotel_reservations')->where('number', 'ilike', $like))
                ->orWhereIn('guest_id', fn ($g) => $g->select('id')->from('hotel_guests')->where('name', 'ilike', $like)));
        }

        return $this->listed($request, $query, StayResource::class, $export);
    }

    public function store(StoreReservationRequest $request): JsonResponse
    {
        $reservation = $this->reservations->create($request->validated(), $request->user());

        return ReservationResource::make($reservation)->detail()->response()->setStatusCode(201);
    }

    public function show(ShowReservationRequest $request, Reservation $hotelReservation): ReservationResource
    {
        return ReservationResource::make($hotelReservation->load(['guest', 'branch', 'stays.roomType', 'stays.ratePlan', 'stays.room', 'stays.guest', 'stays.folio', 'folios']))->detail();
    }

    public function walkIn(WalkInRequest $request): JsonResponse
    {
        $reservation = $this->walkIns->create($request->validated(), $request->user());

        return ReservationResource::make($reservation)->detail()->response()->setStatusCode(201);
    }
}
