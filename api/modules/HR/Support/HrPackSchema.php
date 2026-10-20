<?php

namespace Modules\HR\Support;

use App\Core\CountryPacks\PackSchemas;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * ADM-04b (ADR 022): the structure of an HR pack release: identifier types
 * (a pattern, when given, must be a valid regular expression) and
 * statutory leave types with their days, by effective period.
 */
final class HrPackSchema
{
    public static function register(PackSchemas $schemas): void
    {
        $schemas->register('hr', fn (array $content) => self::check($content), ['identifier_types' => ['key'], 'statutory_leave_types' => ['key', 'effective_from']], ['statutory_leave_types' => 'key'],
            ['identifier_types' => ['key', 'required', 'pattern'], 'statutory_leave_types' => ['key', 'accrual', 'count_basis', 'paid', 'days', 'needs_confirmation', 'effective_from', 'effective_to']]);
    }

    /** @param array<string, mixed> $content */
    public static function check(array $content): void
    {
        $validator = Validator::make($content, [
            'code' => ['required', 'string', 'regex:/^[A-Z]{2}\z/'],
            'sources' => ['present', 'array'],
            'todo' => ['present', 'array'],
            'identifier_types' => ['present', 'array'],
            'identifier_types.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}\z/', 'distinct'],
            'identifier_types.*.required' => ['required', 'boolean'],
            'identifier_types.*.pattern' => ['present', 'nullable', 'string', 'max:200'],
            'statutory_leave_types' => ['present', 'array'],
            'statutory_leave_types.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}\z/'],
            'statutory_leave_types.*.days' => ['present', 'nullable', 'integer', 'min:0', 'max:366'],
            'statutory_leave_types.*.needs_confirmation' => ['required', 'boolean'],
            'statutory_leave_types.*.effective_from' => ['required', 'date_format:Y-m-d'],
            'statutory_leave_types.*.effective_to' => ['present', 'nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        foreach ($content['identifier_types'] as $type) {
            if (($type['pattern'] ?? null) !== null && @preg_match('/'.(string) $type['pattern'].'/u', '') === false) {
                throw new InvalidArgumentException("identifier_types.{$type['key']}: the pattern is not a valid regular expression.");
            }
        }
    }
}
