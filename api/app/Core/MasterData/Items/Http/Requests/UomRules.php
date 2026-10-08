<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Items\Uom;
use Illuminate\Validation\Rule;

/**
 * MD-02 unit validation: the code is upper case letters, digits and
 * underscores (up to 10), unique among the tenant's active units (checked
 * case-insensitively when saving); both names; a kind.
 */
final class UomRules
{
    public const CODE_PATTERN = '/^[A-Z0-9_]{1,10}\z/';

    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'code' => [...$required, 'string', 'regex:'.self::CODE_PATTERN],
            'name_en' => [...$required, 'string', 'max:100'],
            'name_fr' => [...$required, 'string', 'max:100'],
            'kind' => [...$required, 'string', Rule::in(Uom::KINDS)],
        ];
    }

    /** Codes are compared upper case. */
    public static function prepare(array $input): array
    {
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = strtoupper(trim($input['code']));
        }

        return $input;
    }

    /** @return array<string, string> */
    public static function attributeNames(): array
    {
        return collect(['code' => 'code', 'name_en' => 'name_en', 'name_fr' => 'name_fr', 'kind' => 'kind'])
            ->map(fn (string $key) => __("core.uom.attributes.{$key}"))->all();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['code.regex' => __('core.uom.code_invalid')];
    }
}
