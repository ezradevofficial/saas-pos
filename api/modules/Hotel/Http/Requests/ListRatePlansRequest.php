<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;

/** GET hotel/rate-plans (HOT-06): `hotel.rate.view`; `?company=`, `?room_type=`, `?status=active|archived|all`, `?search=`. */
class ListRatePlansRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.rate.view';
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'room_type' => ['sometimes', 'uuid', Rule::exists('hotel_room_types', 'id')],
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
