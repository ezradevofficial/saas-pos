<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Support\Access;

/**
 * POST hotel/rooms/{hotel_room}/status (HOT-05, HOT-29): `{status, reason?, from?, until?}`.
 * Dirty ⇄ clean needs `hotel.housekeeping.update` (a housekeeper's, limited to those two); `out_of_order`
 * (with a reason, dates optional) and taking a room back from it need `hotel.room.manage`.
 */
class RoomStatusRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.housekeeping.update';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_room')?->branch_id;
    }

    public function authorize(): bool
    {
        $branch = $this->bound('hotel_room')->branch_id;
        $access = app(Access::class);

        return $access->can($this->user(), 'hotel.housekeeping.update', $branch) || $access->can($this->user(), 'hotel.room.manage', $branch);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([Room::VACANT_CLEAN, Room::VACANT_DIRTY, Room::OUT_OF_ORDER])],
            'reason' => ['required_if:status,out_of_order', 'nullable', 'string', 'max:500'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $room = $this->bound('hotel_room');
            $access = app(Access::class);
            $manager = $access->can($this->user(), 'hotel.room.manage', $room->branch_id);
            $touchesOutOfOrder = $this->input('status') === Room::OUT_OF_ORDER || $room->status === Room::OUT_OF_ORDER;

            if ($touchesOutOfOrder && ! $manager) {
                $validator->errors()->add('status', __('hotel.errors.place_forbidden'));
            }
        }];
    }

    /** Whether this change is a housekeeper's (dirty ⇄ clean only), not a room manager's. */
    public function byHousekeeper(): bool
    {
        return ! app(Access::class)->can($this->user(), 'hotel.room.manage', $this->bound('hotel_room')->branch_id);
    }
}
