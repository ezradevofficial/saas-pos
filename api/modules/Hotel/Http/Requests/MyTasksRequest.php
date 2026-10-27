<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;

/** GET hotel/housekeeping/my-tasks (HOT-29): `hotel.housekeeping.update`; the signed-in housekeeper's open tasks (and the unassigned ones of their branches) with each room's status, for the phone. No guest data. */
class MyTasksRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.housekeeping.update';
    }

    public function rules(): array
    {
        return ['branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')]];
    }
}
