<?php

namespace Modules\FixedAssets\TaxBook;

use App\Core\CountryPacks\PackSchemas;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * ADM-04b (ADR 022): the structure of a fixed assets pack release: the tax
 * wear-and-tear classes by effective period (a method and an annual rate
 * as a percentage string) and the year-of-acquisition rule.
 */
final class FixedAssetsPackSchema
{
    public static function register(PackSchemas $schemas): void
    {
        $schemas->register('fixed_assets', fn (array $content) => self::check($content), ['classes' => ['key', 'effective_from']], ['classes' => 'key']);
    }

    /** @param array<string, mixed> $content */
    public static function check(array $content): void
    {
        $validator = Validator::make($content, [
            'code' => ['required', 'string', 'regex:/^[A-Z]{2}\z/'],
            'sources' => ['present', 'array'],
            'todo' => ['present', 'array'],
            'acquisition_rule' => ['required', 'array'],
            'acquisition_rule.value' => ['present', 'nullable', 'string', 'in:'.implode(',', TaxClasses::ACQUISITION_RULES)],
            'acquisition_rule.needs_confirmation' => ['required', 'boolean'],
            'classes' => ['required', 'array'],
            'classes.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,59}\z/'],
            'classes.*.method' => ['present', 'nullable', 'string', 'in:'.implode(',', TaxClasses::METHODS)],
            'classes.*.rate' => ['present', 'nullable', 'regex:/^\d{1,3}(\.\d{1,4})?\z/'],
            'classes.*.needs_confirmation' => ['required', 'boolean'],
            'classes.*.effective_from' => ['required', 'date_format:Y-m-d'],
            'classes.*.effective_to' => ['present', 'nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }
    }
}
