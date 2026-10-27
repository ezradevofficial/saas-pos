<?php

namespace Modules\Hotel\Http\Requests;

/** The fields of a room type, shared by create and update (HOT-03). */
final class RoomTypeFields
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'base_adults' => ['sometimes', 'integer', 'between:1,20'],
            'max_adults' => ['sometimes', 'integer', 'between:1,20'],
            'max_children' => ['sometimes', 'integer', 'between:0,20'],
            'max_occupancy' => ['sometimes', 'integer', 'between:1,40'],
            'beds' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'integer', 'between:0,10000'],
        ];
    }
}
