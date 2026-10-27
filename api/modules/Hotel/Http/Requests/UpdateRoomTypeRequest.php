<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Validator;

/** PATCH hotel/room-types/{hotel_room_type} (HOT-03): `hotel.room.manage` at the type's company. */
class UpdateRoomTypeRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.room.manage';
    }

    protected function companyId(): ?string
    {
        return $this->bound('hotel_room_type')?->company_id;
    }

    public function rules(): array
    {
        return RoomTypeFields::rules(updating: true);
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = $this->bound('hotel_room_type');
            $base = (int) $this->input('base_adults', $type->base_adults);
            $adults = (int) $this->input('max_adults', $type->max_adults);
            $occupancy = (int) $this->input('max_occupancy', $type->max_occupancy);

            if ($adults < $base || $occupancy < $base) {
                $validator->errors()->add('max_adults', __('hotel.errors.occupancy_range'));
            }
        }];
    }
}
