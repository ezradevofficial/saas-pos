<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Audit\Auditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hotel\Guests\Guests;
use Modules\Hotel\Http\Requests\ListGuestsRequest;
use Modules\Hotel\Http\Requests\ShowGuestRequest;
use Modules\Hotel\Http\Requests\StoreGuestRequest;
use Modules\Hotel\Http\Requests\UpdateGuestRequest;
use Modules\Hotel\Http\Resources\GuestResource;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Support\Access;
use Modules\Hotel\Support\HotelError;

/** HOT-16, HOT-38: guest records. The identity document is masked unless the reader holds `hotel.guest.identity` and asks to reveal it (audited). */
class GuestController
{
    public function __construct(private readonly Access $access, private readonly Guests $guests, private readonly Auditor $auditor) {}

    public function index(ListGuestsRequest $request): AnonymousResourceCollection
    {
        $query = $this->access->scopeCompanies(Guest::query(), $request->user(), 'hotel.guest.view')->orderBy('name')->orderBy('id');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        if ($request->filled('company')) {
            $query->where('company_id', $request->validated('company'));
        }

        if ($request->filled('search')) {
            $like = '%'.addcslashes((string) $request->validated('search'), '\\%_').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('phone', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }

        return GuestResource::collection($query->paginate((int) $request->validated('per_page', 25))->withQueryString());
    }

    public function store(StoreGuestRequest $request): JsonResponse
    {
        $guest = $this->guests->create($request->validated('company_id'), $request->validated(), $request->user());

        return GuestResource::make($guest)->response()->setStatusCode(201);
    }

    public function show(ShowGuestRequest $request, Guest $hotelGuest): GuestResource
    {
        $reveal = $request->reveals();

        if ($reveal) {
            if (! $this->access->canCompany($request->user(), 'hotel.guest.identity', $hotelGuest->company_id)) {
                throw HotelError::make(403, 'identity_forbidden');
            }

            // HOT-38: showing an identity document in full is audited, by who and which guest, never the value (NFR-08).
            $this->auditor->record('hotel.guest.identity_view', $hotelGuest, null, ['guest_id' => $hotelGuest->id]);
        }

        return GuestResource::make($hotelGuest)->full($reveal);
    }

    public function update(UpdateGuestRequest $request, Guest $hotelGuest): GuestResource
    {
        return GuestResource::make($this->guests->update($hotelGuest, $request->validated(), $request->user()));
    }
}
