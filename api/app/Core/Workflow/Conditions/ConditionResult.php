<?php

namespace App\Core\Workflow\Conditions;

/**
 * The outcome of a condition: whether it holds, every comparison made, and
 * the comparisons that made it fail (the reasons a blocked move shows,
 * WF-04). An empty condition always holds.
 */
final class ConditionResult
{
    /**
     * @param  list<ConditionCheck>  $checks  every comparison, in order
     * @param  list<ConditionCheck>  $failures  the comparisons that explain a failure (empty when it holds)
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $checks = [],
        public readonly array $failures = [],
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    /** @return array{passed: bool, checks: list<array<string, mixed>>, failures: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'checks' => array_map(fn (ConditionCheck $check) => $check->toArray(), $this->checks),
            'failures' => array_map(fn (ConditionCheck $check) => $check->toArray(), $this->failures),
        ];
    }
}
