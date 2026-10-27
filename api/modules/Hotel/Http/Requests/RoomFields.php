<?php

namespace Modules\Hotel\Http\Requests;

/** The fields of a room besides its place and number, shared by create and update (HOT-04). */
final class RoomFields
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'floor' => ['sometimes', 'nullable', 'string', 'max:20'],
            'features' => ['sometimes', 'array', 'max:30'],
            'features.*' => ['string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
