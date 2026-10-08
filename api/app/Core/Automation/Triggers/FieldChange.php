<?php

namespace App\Core\Automation\Triggers;

use App\Core\Currency\Money;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\FieldDefinition;

/**
 * AUTO-01 "field changed" and "threshold crossed": compares a field's value
 * before and after a change with the workflow ConditionEvaluator, so
 * numbers, money (minor units, one currency), dates (by day in the
 * company's time zone) and enums compare exactly as in conditions.
 */
final class FieldChange
{
    public function __construct(private readonly ConditionEvaluator $conditions) {}

    /** Whether the value differs (money in another currency counts as a change). */
    public function changed(FieldDefinition $field, mixed $old, mixed $new, string $timezone = 'UTC'): bool
    {
        $oldEmpty = self::isEmpty($old);
        $newEmpty = self::isEmpty($new);

        if ($oldEmpty || $newEmpty) {
            return $oldEmpty !== $newEmpty;
        }

        if ($field->type === 'money') {
            $a = self::plain($old);
            $b = self::plain($new);

            if (($a['currency'] ?? null) !== ($b['currency'] ?? null)) {
                return true;
            }
        }

        return ! $this->holds($field, $new, 'eq', $old, $timezone);
    }

    /** Whether $value equals $expected for the field (as a condition `eq` would say). */
    public function equals(FieldDefinition $field, mixed $value, mixed $expected, string $timezone = 'UTC'): bool
    {
        if (self::isEmpty($expected)) {
            return self::isEmpty($value);
        }

        return $this->holds($field, $value, 'eq', $expected, $timezone);
    }

    /**
     * Threshold crossed between two values: `down` when it was at or above
     * the threshold and is now below it, `up` when it was at or below and
     * is now above it. A missing value, or money in another currency than
     * the threshold's, never crosses.
     */
    public function crossed(FieldDefinition $field, mixed $threshold, string $direction, mixed $old, mixed $new): bool
    {
        if (self::isEmpty($old) || self::isEmpty($new)) {
            return false;
        }

        return $direction === 'down'
            ? $this->holds($field, $old, 'gte', $threshold) && $this->holds($field, $new, 'lt', $threshold)
            : $this->holds($field, $old, 'lte', $threshold) && $this->holds($field, $new, 'gt', $threshold);
    }

    private function holds(FieldDefinition $field, mixed $value, string $op, mixed $expected, string $timezone = 'UTC'): bool
    {
        $condition = ['field' => $field->name, 'op' => $op, 'value' => self::plain($expected)];

        return $this->conditions->evaluate($condition, [$field->name => $value], [$field->name => $field], $timezone)->passed;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof Money ? $value->jsonSerialize() : $value;
    }
}
