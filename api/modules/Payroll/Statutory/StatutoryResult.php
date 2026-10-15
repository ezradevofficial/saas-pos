<?php

namespace Modules\Payroll\Statutory;

use Brick\Math\BigInteger;

/**
 * PAY-07: the engine's answer for one employee: each component's shares
 * (minor units of the pack currency, digit strings), the taxable pay and
 * the gross including statutory allowances.
 */
final class StatutoryResult
{
    /** @param list<array{code: string, kind: string, method: string, base_minor: string, employee_minor: string, employer_minor: string, trace: array<string, mixed>}> $components */
    public function __construct(
        public readonly array $components,
        public readonly string $taxablePay,
        public readonly string $gross,
    ) {}

    /** What the employee has deducted (contributions, levies and tax; allowances are paid, not deducted). */
    public function employeeDeductions(): string
    {
        return $this->sum(fn (array $c) => $c['kind'] === 'allowance' ? '0' : $c['employee_minor']);
    }

    /** Statutory allowances paid to the employee. */
    public function allowances(): string
    {
        return $this->sum(fn (array $c) => $c['kind'] === 'allowance' ? $c['employee_minor'] : '0');
    }

    public function employer(): string
    {
        return $this->sum(fn (array $c) => $c['employer_minor']);
    }

    /** @return array<string, mixed>|null */
    public function component(string $code): ?array
    {
        foreach ($this->components as $component) {
            if ($component['code'] === $code) {
                return $component;
            }
        }

        return null;
    }

    private function sum(callable $amount): string
    {
        $total = BigInteger::zero();

        foreach ($this->components as $component) {
            $total = $total->plus($amount($component));
        }

        return (string) $total;
    }
}
