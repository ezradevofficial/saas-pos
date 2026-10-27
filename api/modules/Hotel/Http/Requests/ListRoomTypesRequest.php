<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;

/** GET hotel/room-types (HOT-03): `hotel.room.view`; `?company=`, `?status=active|archived|all`, `?search=`. */
class ListRoomTypesRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.room.view';
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
