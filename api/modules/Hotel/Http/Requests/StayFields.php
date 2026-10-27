<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;

/** The fields of one stay line of a booking (HOT-12), with a key prefix (`stays.*.`). */
final class StayFields
{
    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix): array
    {
        return [
            "{$prefix}room_type_id" => HotelRules::id('hotel_room_types'),
            "{$prefix}rate_plan_id" => HotelRules::id('hotel_rate_plans'),
            "{$prefix}arrival_on" => HotelRules::day(),
            "{$prefix}departure_on" => [...HotelRules::day(), "after:{$prefix}arrival_on"],
            "{$prefix}adults" => ['sometimes', 'integer', 'between:1,20'],
            "{$prefix}children" => ['sometimes', 'integer', 'between:0,20'],
            "{$prefix}rooms" => ['sometimes', 'integer', 'between:1,100'],
            "{$prefix}guest_id" => ['sometimes', 'uuid', Rule::exists('hotel_guests', 'id')->whereNull('archived_at')],
            "{$prefix}notes" => ['sometimes', 'nullable', 'string', 'max:1000'],
            "{$prefix}bill_to_master" => ['sometimes', 'boolean'],
            "{$prefix}rate_minor" => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            "{$prefix}rate_reason" => ["required_with:{$prefix}rate_minor", 'nullable', 'string', 'max:500'],
        ];
    }
}
