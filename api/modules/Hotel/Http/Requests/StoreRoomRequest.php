<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Tenancy\Models\Branch;
use Illuminate\Validation\Validator;
use Modules\Hotel\Models\RoomType;

/**
 * POST hotel/rooms (HOT-04): `hotel.room.manage` at the branch.
 * `{branch_id, room_type_id, number, floor?, features?: [tag], notes?}`; the number is unique in the branch (ignoring case).
 */
class StoreRoomRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.room.manage';
    }

    protected function branches(): array
    {
        return ['branch_id' => 'hotel.room.manage'];
    }

    public function rules(): array
    {
        return [
            'branch_id' => HotelRules::branch(),
            'room_type_id' => HotelRules::id('hotel_room_types'),
            'number' => ['required', 'string', 'max:20', HotelRules::unique('hotel_rooms', 'number', ['branch_id' => $this->input('branch_id')])],
            ...RoomFields::rules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = RoomType::query()->find($this->input('room_type_id'));
            $branchCompany = Branch::query()->whereKey($this->input('branch_id'))->value('company_id');

            if ($type === null || $type->company_id !== $branchCompany || $type->archived_at !== null) {
                $validator->errors()->add('room_type_id', __('hotel.errors.room_type_archived'));
            }
        }, ...parent::after()];
    }
}
