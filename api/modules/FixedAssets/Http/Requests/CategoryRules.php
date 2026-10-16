<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FixedAssets\Depreciation\AssetBook;

/** FA-02: the rules of a category's fields. */
final class CategoryRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $required): array
    {
        $need = $required ? 'required' : 'sometimes';

        return [
            'code' => [$need, 'string', 'max:30', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'name' => [$need, 'string', 'max:120'],
            'method' => [$need, 'string', Rule::in(AssetBook::METHODS)],
            'useful_life_months' => ['sometimes', 'nullable', 'integer', 'between:1,1200'],
            'residual_percent' => ['sometimes', 'decimal:0,4', 'between:0,100'],
            'declining_rate' => ['sometimes', 'nullable', 'decimal:0,4', 'gt:0', 'max:100'],
            'tax_class' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
