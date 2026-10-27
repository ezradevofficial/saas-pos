<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;

/** GET hotel/guests (HOT-16): `hotel.guest.view` at the reader's companies; `?company=`, `?search=` (name, phone, email), `?archived=1`. Personal data is masked or left out by the resource. */
class ListGuestsRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.guest.view';
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'search' => ['sometimes', 'string', 'max:100'],
            'archived' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
