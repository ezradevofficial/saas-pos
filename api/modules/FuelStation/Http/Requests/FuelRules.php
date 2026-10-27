<?php

namespace Modules\FuelStation\Http\Requests;

use App\Core\Tenancy\Http\Requests\ListRequest;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Validation rules the module's requests share. Ids are looked up under row-level security, so another tenant's does not exist. */
final class FuelRules
{
    /** @return list<mixed> */
    public static function company(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')];
    }

    /** @return list<mixed> a location that is an active station */
    public static function station(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'uuid', Rule::exists('fuel_stations', 'location_id')->whereNull('archived_at')];
    }

    /** @return list<mixed> */
    public static function id(string $table, bool $required = true, bool $active = false): array
    {
        $exists = Rule::exists($table, 'id');

        return [$required ? 'required' : 'sometimes', 'uuid', $active ? $exists->whereNull('archived_at') : $exists];
    }

    /** @return list<mixed> a user of the tenant */
    public static function user(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'uuid', Rule::exists('users', 'id')];
    }

    /** @return list<mixed> an amount in minor units (digits or an integer) */
    public static function minor(bool $required = true, int $min = 0): array
    {
        return [$required ? 'required' : 'sometimes', 'integer', 'min:'.$min, 'max:999999999999999'];
    }

    /** @return list<mixed> litres or a meter reading: digits with up to 3 decimals */
    public static function litres(bool $required = true, bool $nullable = false): array
    {
        return [$required ? 'required' : 'sometimes', ...($nullable ? ['nullable'] : []), 'regex:/^\d{1,13}(\.\d{1,3})?$/'];
    }

    /** @return list<mixed> a dip in cm with up to 2 decimals */
    public static function cm(bool $required = true, bool $nullable = false): array
    {
        return [$required ? 'required' : 'sometimes', ...($nullable ? ['nullable'] : []), 'regex:/^\d{1,5}(\.\d{1,2})?$/'];
    }

    /** @return list<mixed> */
    public static function currency(): array
    {
        return ['required', 'string', 'regex:/^[A-Z]{3}$/', Rule::exists('tenant_currencies', 'code')->where('active', true)];
    }

    /** @return list<mixed> */
    public static function reason(bool $required = true): array
    {
        return $required ? ['required', 'string', 'min:2', 'max:500'] : ['sometimes', 'nullable', 'string', 'max:500'];
    }

    /** @return array<string, list<mixed>> */
    public static function paging(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    /** @return array<string, list<mixed>> */
    public static function period(): array
    {
        return ['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']];
    }

    /** A rule: no other row of $table has the same $column (ignoring case) within $scope (column => value), apart from $ignoreId. */
    public static function unique(string $table, string $column, array $scope, ?string $ignoreId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table, $column, $scope, $ignoreId) {
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
}
