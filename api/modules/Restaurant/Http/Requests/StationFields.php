<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** REST-08: a station's fields. */
final class StationFields
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'kind' => [$req, Rule::in(['kitchen', 'bar', 'other'])],
            'printer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'category_ids' => ['sometimes', 'array', 'max:500'],
            'category_ids.*' => ['uuid', 'distinct', Rule::exists('item_categories', 'id')],
        ];
    }
}
