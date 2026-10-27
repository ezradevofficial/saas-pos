<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;
use Modules\Hotel\Http\Lists\StayList;
use Modules\Hotel\Models\Reservation;
use Modules\Hotel\Models\Stay;

/**
 * GET hotel/reservations (HOT-12): `hotel.reservation.view` at the reader's branches. Rows are stays with their booking:
 * `?branch=`, `?status=`, `?kind=`, `?arrival_from=`, `?arrival_to=`, `?room_type=`, `?search=` (booking number, guest name), `?sort=`, pages and an export.
 */
class ListReservationsRequest extends HotelRequest
{
    use SortsAndExports;

    protected function permission(): string
    {
        return 'hotel.reservation.view';
    }

    public function list(): StayList
    {
        return new StayList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'status' => ['sometimes', 'string', Rule::in([...Stay::STATUSES, 'all', 'open'])],
            'kind' => ['sometimes', 'string', Rule::in(Reservation::KINDS)],
            'room_type' => ['sometimes', 'uuid', Rule::exists('hotel_room_types', 'id')],
            'arrival_from' => HotelRules::day(false),
            'arrival_to' => HotelRules::day(false),
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
