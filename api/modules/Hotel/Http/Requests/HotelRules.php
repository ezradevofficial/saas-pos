<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Validation rules Hotel's requests share. Ids are looked up under row-level security, so another tenant's does not exist. */
final class HotelRules
{
    /** @return list<mixed> */
    public static function branch(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'uuid', Rule::exists('branches', 'id')->whereNull('archived_at')];
    }

    /** @return list<mixed> */
    public static function company(): array
    {
        return ['required', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')];
    }

    /** @return list<mixed> */
    public static function id(string $table, bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'uuid', Rule::exists($table, 'id')];
    }

    /** @return list<mixed> a day, Y-m-d */
    public static function day(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'date_format:Y-m-d'];
    }

    /** @return list<mixed> an amount in minor units, 0 to 18 digits (a string or an integer) */
    public static function minor(bool $required = true, int $min = 0): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:'.$min, 'max:999999999999999'];
    }

    /** A rule: no other row of $table has the same $column (ignoring case) within $scope (column => value), apart from $ignoreId. */
    public static function unique(string $table, string $column, array $scope, ?string $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($table, $column, $scope, $ignoreId) {
            $query = DB::table($table)->whereRaw("lower({$column}) = ?", [mb_strtolower((string) $value)]);

            foreach ($scope as $key => $scoped) {
                $query->where($key, $scoped);
            }

            if ($ignoreId !== null) {
                $query->where('id', '<>', $ignoreId);
            }

            if ($query->exists()) {
                $fail(__('validation.unique', ['attribute' => $attribute]));
            }
        };
    }

    /** @return list<mixed> */
    public static function reason(bool $required = true): array
    {
        return $required ? ['required', 'string', 'min:2', 'max:500'] : ['sometimes', 'nullable', 'string', 'max:500'];
    }
}
