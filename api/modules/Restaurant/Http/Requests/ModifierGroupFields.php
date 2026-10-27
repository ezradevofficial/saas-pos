<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** REST-07: a modifier group's fields, its modifiers and what it attaches to. */
final class ModifierGroupFields
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'min_select' => [$req, 'integer', 'min:0', 'max:20'],
            'max_select' => [$req, 'integer', 'min:1', 'max:20', 'gte:min_select'],
            'item_ids' => ['sometimes', 'array', 'max:500'],
            'item_ids.*' => ['uuid', 'distinct', Rule::exists('items', 'id')],
            'category_ids' => ['sometimes', 'array', 'max:500'],
            'category_ids.*' => ['uuid', 'distinct', Rule::exists('item_categories', 'id')],
            'modifiers' => ['sometimes', 'array', 'max:100'],
            'modifiers.*.id' => ['sometimes', 'nullable', 'uuid'],
            'modifiers.*.name' => ['required', 'string', 'max:120'],
            'modifiers.*.price_minor' => ['required', 'string', 'regex:/^\d{1,15}$/'],
            'modifiers.*.sort' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
