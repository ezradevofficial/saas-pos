<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\Settings;

/** The fields of a rate plan and its seasons, shared by create and update (HOT-06 to HOT-09). Money is in minor units. */
final class RatePlanFields
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $charge = ['sometimes', 'nullable', 'string', Rule::in(Settings::CHARGE_KINDS)];

        return [
            'name' => [...$required, 'string', 'max:100'],
            'board' => [...$required, 'string', Rule::in(RatePlan::BOARDS)],
            'weekday_rate_minor' => [...$required, 'integer', 'min:0', 'max:999999999999999'],
            'weekend_rate_minor' => [...$required, 'integer', 'min:0', 'max:999999999999999'],
            'extra_adult_minor' => HotelRules::minor(false),
            'child_rate_minor' => HotelRules::minor(false),
            'free_children' => ['sometimes', 'integer', 'between:0,20'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'cancel_free_days' => ['sometimes', 'nullable', 'integer', 'between:0,365'],
            'cancel_charge_kind' => $charge,
            'cancel_charge_value' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
            'noshow_charge_kind' => $charge,
            'noshow_charge_value' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
            'seasons' => ['sometimes', 'array', 'max:50'],
            'seasons.*.name' => ['required', 'string', 'max:100'],
            'seasons.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'seasons.*.ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:seasons.*.starts_on'],
            'seasons.*.weekday_rate_minor' => ['required', 'integer', 'min:0', 'max:999999999999999'],
            'seasons.*.weekend_rate_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
            'seasons.*.priority' => ['sometimes', 'integer', 'between:0,100'],
        ];
    }
}
