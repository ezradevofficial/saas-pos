<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Models\RoomType;
use Modules\Hotel\Support\Access;

/**
 * POST hotel/rate-plans (HOT-06): `hotel.rate.manage` at the company of the room type.
 * `{room_type_id, code, name, board, currency, weekday_rate_minor, weekend_rate_minor, extra_adult_minor?, child_rate_minor?, free_children?,
 * tax_inclusive?, valid_from?, valid_until?, cancel_*?, noshow_*?, seasons?: [{name, starts_on, ends_on, weekday_rate_minor, weekend_rate_minor?, priority?}]}`.
 */
class StoreRatePlanRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.rate.manage';
    }

    public function rules(): array
    {
        $companyId = RoomType::query()->whereKey($this->input('room_type_id'))->value('company_id');

        return [
            'room_type_id' => HotelRules::id('hotel_room_types'),
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-\.]+$/', HotelRules::unique('hotel_rate_plans', 'code', ['company_id' => $companyId])],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            ...RatePlanFields::rules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = RoomType::query()->find($this->input('room_type_id'));
            app(Access::class)->requireCompany($this->user(), 'hotel.rate.manage', $type->company_id);

            if ($type->archived_at !== null) {
                $validator->errors()->add('room_type_id', __('hotel.errors.room_type_archived'));
            }
        }];
    }
}
