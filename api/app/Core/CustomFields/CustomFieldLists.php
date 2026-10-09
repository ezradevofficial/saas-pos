<?php

namespace App\Core\CustomFields;

use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Lists\ListSort;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * CF-03, CF-06: custom fields in the lists of an entity (lists and pickers
 * plan): a column and, for scalar types, a sort per active field, keyed
 * `cf_<key>`, and filters on `?custom[<key>]=value` (equality; text
 * contains; a multi-select holds the value) or
 * `?custom[<key>][min]=..&[max]=..` (numbers, dates, date-times).
 *
 * Equalities use the entity table's GIN index (`custom @> {...}`), ranges
 * and sorts read `custom ->> 'key'`: no field needs its own index or a
 * deploy. Columns read the API resource's `custom` (so hidden fields are
 * never exported) and are named `custom.<key>` for field rules (RBAC-05);
 * a filter or sort on a hidden field is refused like any other (422).
 */
class CustomFieldLists
{
    public const PREFIX = 'cf_';

    public function __construct(private readonly CustomFieldDefinitions $definitions) {}

    /** @return list<ListColumn> */
    public function columns(string $entity): array
    {
        return $this->definitions->active($entity)->map(fn (CustomFieldDefinition $field) => new ListColumn(
            self::PREFIX.$field->key,
            $field->label,
            [CustomFieldAccess::PREFIX.$field->key],
            fn (array $row) => array_key_exists($field->key, (array) ($row['custom'] ?? []))
                ? CustomFieldPresenter::display($field, ((array) $row['custom'])[$field->key])
                : null,
            literal: true,
        ))->values()->all();
    }

    /** @return array<string, ListSort> */
    public function sorts(string $entity): array
    {
        $sorts = [];

        foreach ($this->definitions->active($entity) as $field) {
            if (! in_array($field->type, CustomFieldTypes::SORTABLE, true)) {
                continue;
            }

            $numeric = $field->type === 'number' || ($field->type === 'formula' && ($field->formula_type ?? 'number') === 'number');
            $money = $field->type === 'money';
            $key = $field->key;

            $sorts[self::PREFIX.$key] = ListSort::by([CustomFieldAccess::PREFIX.$key], function (Builder $query, string $direction) use ($numeric, $money, $key) {
                $column = $query->qualifyColumn('custom');
                // Money sorts by its amount in minor units, whatever the currency.
                $expression = match (true) {
                    $money => "({$column} -> ? ->> 'amount_minor')::numeric",
                    $numeric => "nullif({$column} ->> ?, '')::numeric",
                    default => "{$column} ->> ?",
                };
                $query->orderByRaw("{$expression} {$direction} nulls last", [$key]);
            });
        }

        return $sorts;
    }

    /**
     * The rule for `?custom`: known, filterable fields the user can see, with
     * a value of the field's shape.
     */
    public function filterRule(string $entity, ListDefinition $list, Request $request): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($entity, $list, $request) {
            if (! is_array($value)) {
                $fail(__('core.custom_field.filter_invalid'));

                return;
            }

            $fields = $this->definitions->byKey($entity);
            $hidden = $list->hiddenFields($request);

            foreach ($value as $key => $filter) {
                $field = $fields[(string) $key] ?? null;

                if ($field === null || ! in_array($field->type, CustomFieldTypes::FILTERABLE, true)) {
                    $fail(__('core.custom_field.filter_unknown', ['field' => (string) $key]));

                    return;
                }

                if (in_array(CustomFieldAccess::PREFIX.$field->key, $hidden, true)) {
                    // RBAC-05: the matching rows alone would reveal the hidden values.
                    $fail(__('core.list.filter_hidden'));

                    return;
                }

                if ($this->condition($field, $filter) === null) {
                    $fail(__('core.custom_field.filter_invalid'));

                    return;
                }
            }
        };
    }

    /** Apply validated `?custom` filters. */
    public function apply(Builder $query, string $entity, ?array $filters): Builder
    {
        $fields = $this->definitions->byKey($entity);

        foreach ($filters ?? [] as $key => $filter) {
            $field = $fields[(string) $key] ?? null;
            $condition = $field === null ? null : $this->condition($field, $filter);

            if ($condition !== null) {
                $condition($query, $query->qualifyColumn('custom'));
            }
        }

        return $query;
    }

    /**
     * The filters as printed on a PDF: label => value.
     *
     * @return array<string, string>
     */
    public function summary(string $entity, ?array $filters): array
    {
        $fields = $this->definitions->byKey($entity);
        $summary = [];

        foreach ($filters ?? [] as $key => $filter) {
            if (isset($fields[(string) $key])) {
                $summary[$fields[(string) $key]->label] = is_array($filter)
                    ? trim(($filter['min'] ?? '').' – '.($filter['max'] ?? ''))
                    : (string) $filter;
            }
        }

        return $summary;
    }

    /**
     * The query condition for one filter, or null when the value doesn't fit the field.
     *
     * @return (Closure(Builder, string): void)|null
     */
    private function condition(CustomFieldDefinition $field, mixed $filter): ?Closure
    {
        $key = $field->key;
        $type = $field->type === 'formula' ? match ($field->formula_type) {
            'text' => 'text',
            'boolean' => 'boolean',
            default => 'number',
        } : $field->type;
        $contains = fn (mixed $value) => fn (Builder $q, string $column) => $q->whereRaw("{$column} @> ?::jsonb", [json_encode([$key => $value])]);

        if (is_array($filter)) {
            if (! in_array($type, CustomFieldTypes::RANGE_FILTERS, true) || array_diff(array_keys($filter), ['min', 'max']) !== [] || $filter === []) {
                return null;
            }

            $bounds = [];

            foreach (['min' => '>=', 'max' => '<='] as $bound => $op) {
                $value = $filter[$bound] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                if (! is_string($value)) {
                    return null;
                }

                if ($type === 'number') {
                    if (preg_match(CustomFieldTypes::NUMBER_PATTERN, $value) !== 1) {
                        return null;
                    }

                    $bounds[] = fn (Builder $q, string $column) => $q->whereRaw("nullif({$column} ->> ?, '')::numeric {$op} ?::numeric", [$key, $value]);
                } else {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
                        return null;
                    }

                    // Dates and date-times compare by day (date-times stored as UTC ISO 8601).
                    $bounds[] = fn (Builder $q, string $column) => $q->whereRaw("left({$column} ->> ?, 10) {$op} ?", [$key, $value]);
                }
            }

            return fn (Builder $q, string $column) => array_map(fn (Closure $bound) => $bound($q, $column), $bounds);
        }

        if (! is_string($filter) || $filter === '' || mb_strlen($filter) > 255) {
            return null;
        }

        return match ($type) {
            'text', 'long_text' => fn (Builder $q, string $column) => $q->whereRaw("{$column} ->> ? ilike ?", [$key, '%'.addcslashes($filter, '\\%_').'%']),
            'number' => preg_match(CustomFieldTypes::NUMBER_PATTERN, $filter) === 1 ? $contains((string) BigDecimal::of($filter)->strippedOfTrailingZeros()) : null,
            'boolean' => in_array($filter, ['true', '1', 'false', '0'], true) ? $contains(in_array($filter, ['true', '1'], true)) : null,
            'multi_select' => $contains([$filter]),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}\z/', $filter) === 1 ? $contains($filter) : null,
            'datetime' => preg_match('/^\d{4}-\d{2}-\d{2}\z/', $filter) === 1
                ? fn (Builder $q, string $column) => $q->whereRaw("left({$column} ->> ?, 10) = ?", [$key, $filter])
                : null,
            default => $contains($filter),
        };
    }
}
