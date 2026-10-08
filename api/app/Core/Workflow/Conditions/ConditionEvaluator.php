<?php

namespace App\Core\Workflow\Conditions;

use App\Core\Currency\Money;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Evaluates and validates conditions on a document's fields: stage entry
 * and exit rules (WF-04), branches (WF-05) and automation rules (AUTO-02).
 * Stateless and free of the database, so every caller (flows, dry runs,
 * automation) gets the same answer for the same values.
 *
 * A condition is a group or a comparison, nested up to MAX_DEPTH:
 *
 *   {"all": [ ...conditions ]}                       AND (an empty list is refused)
 *   {"any": [ ...conditions ]}                       OR
 *   {"field": "total", "op": "gt", "value": X}       compare with a fixed value
 *   {"field": "total", "op": "gt", "other": "limit"} compare with another field of the same type
 *   {"field": "note", "op": "empty"}                 no value (null, "" or [])
 *
 * Operators per field type (OPERATORS): eq, ne, gt, gte, lt, lte, in,
 * not_in, contains, empty, not_empty. Values: numbers as ints or decimal
 * strings (never floats); money as {"amount_minor": "25000000",
 * "currency": "KES"}, compared in minor units and only within one currency
 * (another currency fails the comparison with `currency_mismatch`, it is
 * never converted); dates as "Y-m-d" (compared by day) or ISO 8601
 * date-times; booleans as true/false; enums as one of the field's values.
 *
 * A null (or empty) condition always holds. All comparisons are evaluated
 * (no short circuit) so a dry run can show every one of them.
 */
final class ConditionEvaluator
{
    public const MAX_DEPTH = 5;

    public const MAX_COMPARISONS = 50;

    /** @var array<string, list<string>> field type => operators */
    public const OPERATORS = [
        'string' => ['eq', 'ne', 'in', 'not_in', 'contains', 'empty', 'not_empty'],
        'reference' => ['eq', 'ne', 'in', 'not_in', 'empty', 'not_empty'],
        'enum' => ['eq', 'ne', 'in', 'not_in', 'empty', 'not_empty'],
        'number' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty'],
        'money' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty'],
        'date' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty'],
        'boolean' => ['eq', 'ne', 'empty', 'not_empty'],
    ];

    private const UNARY = ['empty', 'not_empty'];

    private const LISTS = ['in', 'not_in'];

    /**
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>  $values  field name => value
     * @param  array<string, FieldDefinition>  $fields  field name => definition
     */
    public function evaluate(?array $condition, array $values, array $fields): ConditionResult
    {
        if ($condition === null || $condition === []) {
            return ConditionResult::pass();
        }

        return $this->node($condition, $values, $fields);
    }

    /**
     * Problems with a condition, empty when it is valid. Each problem:
     * `code` (invalid_shape, empty_group, too_deep, too_many, unknown_field,
     * unknown_operator, operator_not_allowed, invalid_value,
     * other_field_mismatch), `path` (dot path inside the condition) and
     * `params`.
     *
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, FieldDefinition>  $fields
     * @return list<array{code: string, path: string, params: array<string, mixed>}>
     */
    public function validate(?array $condition, array $fields): array
    {
        if ($condition === null || $condition === []) {
            return [];
        }

        $problems = [];
        $count = 0;
        $this->check($condition, $fields, '', 1, $problems, $count);

        if ($count > self::MAX_COMPARISONS) {
            $problems[] = ['code' => 'too_many', 'path' => '', 'params' => ['max' => self::MAX_COMPARISONS]];
        }

        return $problems;
    }

    /** The field names a condition reads (for the builder and automation triggers). */
    public function fieldsOf(?array $condition): array
    {
        if ($condition === null || $condition === []) {
            return [];
        }

        $names = [];

        foreach (['all', 'any'] as $group) {
            if (isset($condition[$group]) && is_array($condition[$group])) {
                foreach ($condition[$group] as $child) {
                    array_push($names, ...(is_array($child) ? $this->fieldsOf($child) : []));
                }
            }
        }

        foreach (['field', 'other'] as $key) {
            if (isset($condition[$key]) && is_string($condition[$key])) {
                $names[] = $condition[$key];
            }
        }

        return array_values(array_unique($names));
    }

    // ---- evaluation -------------------------------------------------------

    /** @param array<string, FieldDefinition> $fields */
    private function node(array $node, array $values, array $fields): ConditionResult
    {
        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $node)) {
                return $this->group($group, is_array($node[$group]) ? $node[$group] : [], $values, $fields);
            }
        }

        $check = $this->compare($node, $values, $fields);

        return new ConditionResult($check->passed, [$check], $check->passed ? [] : [$check]);
    }

    /** @param array<string, FieldDefinition> $fields */
    private function group(string $kind, array $children, array $values, array $fields): ConditionResult
    {
        if ($children === []) {
            // An empty group is refused by validate(); at run time it holds nothing back.
            return ConditionResult::pass();
        }

        $results = array_map(fn ($child) => is_array($child)
            ? $this->node($child, $values, $fields)
            : new ConditionResult(false), $children);

        $checks = array_merge(...array_map(fn (ConditionResult $r) => $r->checks, $results));
        $passed = $kind === 'all'
            ? ! in_array(false, array_map(fn (ConditionResult $r) => $r->passed, $results), true)
            : in_array(true, array_map(fn (ConditionResult $r) => $r->passed, $results), true);

        // AND fails because of its failing parts; OR fails because of all of them.
        $failures = $passed ? [] : array_merge(...array_map(fn (ConditionResult $r) => $r->failures, $results));

        return new ConditionResult($passed, $checks, $failures);
    }

    /** @param array<string, FieldDefinition> $fields */
    private function compare(array $node, array $values, array $fields): ConditionCheck
    {
        $name = is_string($node['field'] ?? null) ? $node['field'] : '';
        $op = is_string($node['op'] ?? null) ? $node['op'] : '';
        $field = $fields[$name] ?? null;
        $actual = $values[$name] ?? null;
        $other = isset($node['other']) && is_string($node['other']) ? $node['other'] : null;
        $expected = $other !== null ? ($values[$other] ?? null) : ($node['value'] ?? null);

        $fail = fn (?string $problem = 'invalid_value') => new ConditionCheck($name, $op, $this->plain($expected), $this->plain($actual), false, $other, $problem);

        if ($field === null || ! in_array($op, self::OPERATORS[$field->type], true)) {
            return $fail();
        }

        if (in_array($op, self::UNARY, true)) {
            $passed = $this->isEmpty($actual) === ($op === 'empty');

            return new ConditionCheck($name, $op, null, $this->plain($actual), $passed);
        }

        if (in_array($op, self::LISTS, true)) {
            if (! is_array($expected) || ! array_is_list($expected)) {
                return $fail();
            }

            if ($this->isEmpty($actual)) {
                return new ConditionCheck($name, $op, $expected, null, $op === 'not_in');
            }

            $found = false;

            foreach ($expected as $candidate) {
                $equal = $this->equals($field->type, $actual, $candidate);

                if ($equal === null) {
                    return $fail();
                }

                $found = $found || $equal;
            }

            return new ConditionCheck($name, $op, $expected, $this->plain($actual), $found === ($op === 'in'));
        }

        // A missing value never satisfies a comparison, except "not equal".
        if ($this->isEmpty($actual) || $this->isEmpty($expected)) {
            $passed = $op === 'ne' && $this->isEmpty($actual) !== $this->isEmpty($expected);

            return new ConditionCheck($name, $op, $this->plain($expected), $this->plain($actual), $passed, $other);
        }

        if ($op === 'contains') {
            if (! is_string($actual) || ! is_string($expected)) {
                return $fail();
            }

            return new ConditionCheck($name, $op, $expected, $actual, mb_stripos($actual, $expected) !== false, $other);
        }

        if ($field->type === 'money') {
            $a = $this->money($actual);
            $b = $this->money($expected);

            if ($a === null || $b === null) {
                return $fail();
            }

            if ($a['currency'] !== $b['currency']) {
                return $fail('currency_mismatch');
            }

            $order = BigDecimal::of($a['amount_minor'])->compareTo(BigDecimal::of($b['amount_minor']));

            return new ConditionCheck($name, $op, $b, $a, $this->holds($op, $order), $other);
        }

        $order = $this->order($field->type, $actual, $expected);

        if ($order === null) {
            return $fail();
        }

        return new ConditionCheck($name, $op, $this->plain($expected), $this->plain($actual), $this->holds($op, $order), $other);
    }

    private function holds(string $op, int $order): bool
    {
        return match ($op) {
            'eq' => $order === 0,
            'ne' => $order !== 0,
            'gt' => $order > 0,
            'gte' => $order >= 0,
            'lt' => $order < 0,
            'lte' => $order <= 0,
            default => false,
        };
    }

    /** -1, 0 or 1; null when the values cannot be compared as $type. */
    private function order(string $type, mixed $a, mixed $b): ?int
    {
        switch ($type) {
            case 'number':
                $x = $this->number($a);
                $y = $this->number($b);

                return $x === null || $y === null ? null : $x->compareTo($y);

            case 'date':
                return $this->compareDates($a, $b);

            case 'boolean':
                return is_bool($a) && is_bool($b) ? ($a === $b ? 0 : 1) : null;

            case 'money':
                $x = $this->money($a);
                $y = $this->money($b);

                if ($x === null || $y === null || $x['currency'] !== $y['currency']) {
                    return null;
                }

                return BigDecimal::of($x['amount_minor'])->compareTo(BigDecimal::of($y['amount_minor']));

            default:
                if (! is_string($a) || ! is_string($b)) {
                    return null;
                }

                return $a === $b ? 0 : ($a < $b ? -1 : 1);
        }
    }

    /** Equality for `in` lists; null when the values cannot be compared. */
    private function equals(string $type, mixed $a, mixed $b): ?bool
    {
        $order = $this->order($type, $a, $b);

        return $order === null ? null : $order === 0;
    }

    private function compareDates(mixed $a, mixed $b): ?int
    {
        if (! is_string($a) || ! is_string($b)) {
            return null;
        }

        $dayOnly = $this->isDay($a) || $this->isDay($b);

        try {
            $x = CarbonImmutable::parse($a)->utc();
            $y = CarbonImmutable::parse($b)->utc();
        } catch (Throwable) {
            return null;
        }

        if ($dayOnly) {
            // A day compared with a date-time: compare the days.
            $x = $this->isDay($a) ? $a : $x->toDateString();
            $y = $this->isDay($b) ? $b : $y->toDateString();

            return $x <=> $y;
        }

        return $x <=> $y;
    }

    private function isDay(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function number(mixed $value): ?BigDecimal
    {
        if (is_int($value)) {
            return BigDecimal::of($value);
        }

        if (is_string($value) && preg_match('/^-?\d{1,30}(\.\d{1,18})?$/', $value) === 1) {
            return BigDecimal::of($value);
        }

        return null;
    }

    /** @return array{amount_minor: string, currency: string}|null */
    private function money(mixed $value): ?array
    {
        if ($value instanceof Money) {
            $value = $value->jsonSerialize();
        }

        if (! is_array($value) || ! isset($value['amount_minor'], $value['currency']) || ! is_string($value['currency'])
            || preg_match('/^[A-Z]{3}$/', $value['currency']) !== 1) {
            return null;
        }

        $amount = $value['amount_minor'];

        if (! is_int($amount) && ! (is_string($amount) && preg_match('/^-?\d{1,30}$/', $amount) === 1)) {
            return null;
        }

        return ['amount_minor' => (string) $amount, 'currency' => $value['currency']];
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** Values as JSON can carry them (Money as its array). */
    private function plain(mixed $value): mixed
    {
        return $value instanceof Money ? $value->jsonSerialize() : $value;
    }

    // ---- validation ------------------------------------------------------

    /**
     * @param  array<string, FieldDefinition>  $fields
     * @param  list<array{code: string, path: string, params: array<string, mixed>}>  $problems
     */
    private function check(mixed $node, array $fields, string $path, int $depth, array &$problems, int &$count): void
    {
        $at = fn (string $suffix) => ltrim($path.'.'.$suffix, '.');
        $problem = function (string $code, array $params = []) use (&$problems, $path) {
            $problems[] = ['code' => $code, 'path' => $path, 'params' => $params];
        };

        if (! is_array($node) || array_is_list($node)) {
            $problem('invalid_shape');

            return;
        }

        if ($depth > self::MAX_DEPTH) {
            $problem('too_deep', ['max' => self::MAX_DEPTH]);

            return;
        }

        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $node)) {
                if (count($node) !== 1 || ! is_array($node[$group]) || ! array_is_list($node[$group])) {
                    $problem('invalid_shape');

                    return;
                }

                if ($node[$group] === []) {
                    $problem('empty_group');

                    return;
                }

                foreach ($node[$group] as $i => $child) {
                    $this->check($child, $fields, $at("{$group}.{$i}"), $depth + 1, $problems, $count);
                }

                return;
            }
        }

        $count++;
        $allowed = ['field', 'op', 'value', 'other'];

        if (array_diff(array_keys($node), $allowed) !== [] || ! is_string($node['field'] ?? null) || ! is_string($node['op'] ?? null)) {
            $problem('invalid_shape');

            return;
        }

        $field = $fields[$node['field']] ?? null;

        if ($field === null) {
            $problem('unknown_field', ['field' => $node['field']]);

            return;
        }

        $op = $node['op'];

        if (! in_array($op, array_merge(...array_values(self::OPERATORS)), true)) {
            $problem('unknown_operator', ['op' => $op]);

            return;
        }

        if (! in_array($op, self::OPERATORS[$field->type], true)) {
            $problem('operator_not_allowed', ['op' => $op, 'field' => $field->name, 'type' => $field->type]);

            return;
        }

        $hasValue = array_key_exists('value', $node);
        $hasOther = array_key_exists('other', $node);

        if (in_array($op, self::UNARY, true)) {
            if ($hasValue || $hasOther) {
                $problem('invalid_value', ['field' => $field->name]);
            }

            return;
        }

        if (in_array($op, self::LISTS, true)) {
            if ($hasOther || ! $hasValue || ! is_array($node['value']) || ! array_is_list($node['value']) || $node['value'] === []
                || in_array(false, array_map(fn ($v) => $this->validValue($field, $v), $node['value']), true)) {
                $problem('invalid_value', ['field' => $field->name]);
            }

            return;
        }

        if ($hasValue === $hasOther) {
            $problem('invalid_value', ['field' => $field->name]);

            return;
        }

        if ($hasOther) {
            $other = is_string($node['other']) ? ($fields[$node['other']] ?? null) : null;

            if ($other === null) {
                $problem('unknown_field', ['field' => is_string($node['other']) ? $node['other'] : '']);
            } elseif ($other->type !== $field->type) {
                $problem('other_field_mismatch', ['field' => $field->name, 'other' => $other->name]);
            }

            return;
        }

        if ($op === 'contains' ? ! (is_string($node['value']) && $node['value'] !== '') : ! $this->validValue($field, $node['value'])) {
            $problem('invalid_value', ['field' => $field->name]);
        }
    }

    private function validValue(FieldDefinition $field, mixed $value): bool
    {
        return match ($field->type) {
            'number' => $this->number($value) !== null,
            'money' => $this->money($value) !== null,
            'date' => is_string($value) && $this->parsesAsDate($value),
            'boolean' => is_bool($value),
            'enum' => is_string($value) && in_array($value, $field->values, true),
            default => is_string($value) && $value !== '' && mb_strlen($value) <= 255,
        };
    }

    private function parsesAsDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ][0-9:.]+(Z|[+-]\d{2}:?\d{2})?)?$/', $value) !== 1) {
            return false;
        }

        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
