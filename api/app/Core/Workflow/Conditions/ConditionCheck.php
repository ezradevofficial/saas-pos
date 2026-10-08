<?php

namespace App\Core\Workflow\Conditions;

/**
 * One comparison of a condition and its outcome (WF-04, WF-05, AUTO-02).
 * `expected` is the fixed value, or the other field's value when the
 * comparison is with a field (`other` names it). `problem` says why a
 * comparison could not be made: `currency_mismatch` (money in different
 * currencies is never converted here) or `invalid_value` (a value of the
 * wrong shape); such a comparison fails.
 */
final class ConditionCheck
{
    public function __construct(
        public readonly string $field,
        public readonly string $op,
        public readonly mixed $expected,
        public readonly mixed $actual,
        public readonly bool $passed,
        public readonly ?string $other = null,
        public readonly ?string $problem = null,
    ) {}

    /** @return array{field: string, op: string, expected: mixed, actual: mixed, passed: bool, other: ?string, problem: ?string} */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'op' => $this->op,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'passed' => $this->passed,
            'other' => $this->other,
            'problem' => $this->problem,
        ];
    }
}
