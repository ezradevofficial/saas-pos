<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Hotel\Models\Guest;

/** The fields of a guest (HOT-16), shared by create, update and the inline guest of a booking. Personal data: ID number, date of birth, address. */
final class GuestFields
{
    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix = '', bool $updating = false): array
    {
        // An inline guest (`guest.name`) is required only when `guest` is sent.
        $required = $prefix !== '' ? ['required_with:'.rtrim($prefix, '.')] : ($updating ? ['sometimes', 'required'] : ['required']);

        return [
            "{$prefix}name" => [...$required, 'string', 'max:160'],
            "{$prefix}phone" => ['sometimes', 'nullable', 'string', 'max:30'],
            "{$prefix}email" => ['sometimes', 'nullable', 'string', 'email', 'max:190'],
            "{$prefix}nationality" => ['sometimes', 'nullable', 'string', 'max:60'],
            "{$prefix}id_type" => ['sometimes', 'nullable', 'string', Rule::in(Guest::ID_TYPES)],
            "{$prefix}id_number" => ['sometimes', 'nullable', 'string', 'max:60'],
            "{$prefix}date_of_birth" => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            "{$prefix}address" => ['sometimes', 'nullable', 'string', 'max:500'],
            "{$prefix}notes" => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
