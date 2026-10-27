<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** REST-02: a table's fields, on create (all required) and update (any). */
final class TableFields
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:60'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'x' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'y' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'width' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'height' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'shape' => ['sometimes', Rule::in(['square', 'round', 'rect'])],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
