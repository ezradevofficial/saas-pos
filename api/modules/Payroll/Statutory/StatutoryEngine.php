<?php

namespace Modules\Payroll\Statutory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * PAY-07: the statutory engine. A pure, deterministic function of the
 * pack's components in force (PayrollPack::inForce, already checked by
 * MissingValues) and one employee's pay (StatutoryInput), in minor units
 * of the pack currency. It reads no database and no clock.
 *
 * Order: allowances (added to gross) → contributions and levies on their
 * base → taxable pay (taxable gross − pre-tax deductions − employee
 * contributions that reduce taxable pay, + what exceeds a category's
 * deduction limit) → income tax by bands → the reduction per dependant →
 * the `max_rate` cap → the `min_minor` minimum (on positive taxable pay,
 * at most that pay) → fixed and rate reliefs → floored at zero. Every component is computed exactly and rounded once,
 * by its own pack rounding rule. A conditional component (`when`) whose
 * condition is not met is skipped.
 *
 * Results: per component `employee_minor` (deducted from the employee; an
 * allowance's is paid to them), `employer_minor`, `base_minor` and, for
 * income tax, the reliefs applied.
 */
final class StatutoryEngine
{
    private const SCALE = 12;

    /** @param list<array<string, mixed>> $components */
    public function compute(array $components, StatutoryInput $input): StatutoryResult
    {
        $scale = BigDecimal::of($input->scale);
        $bases = [
            'gross' => BigDecimal::of($input->gross),
            'taxable_gross' => BigDecimal::of($input->taxableGross),
            'contributory_gross' => BigDecimal::of($input->contributoryGross),
            'basic' => BigDecimal::of($input->basic),
            'insurance_premiums' => BigDecimal::of($input->insurancePremiums),
        ];
        $conditions = ['insurance_premiums' => $bases['insurance_premiums']->isPositive(), 'dependants' => $input->dependants > 0, 'children' => $input->children > 0];

        foreach ($input->preTaxByCategory as $category => $minor) {
            $conditions[$category] = BigDecimal::of($minor)->isPositive();
        }

        // CP-01: a component left out while unconfirmed (`unconfirmed: not_applied`) never computes.
        $active = array_values(array_filter($components, fn (array $c) => $c['values'] !== null && ! PayrollPack::notApplied($c) && ($c['when'] === null || ($conditions[$c['when']] ?? false))));
        $results = [];

        // 1. Statutory allowances, paid to the employee.
        foreach ($active as $c) {
            if ($c['kind'] !== 'allowance') {
                continue;
            }

            $v = $c['values'];
            $amount = self::round(BigDecimal::of($v['amount_minor'])->multipliedBy($scale)->multipliedBy($input->children), $v['rounding']);
            $bases['gross'] = $bases['gross']->plus($amount);

            if ($v['taxable']) {
                $bases['taxable_gross'] = $bases['taxable_gross']->plus($amount);
            }

            if ($v['contributory']) {
                $bases['contributory_gross'] = $bases['contributory_gross']->plus($amount);
            }

            $results[] = self::result($c, '0', (string) $amount, '0', ['children' => $input->children, 'taxable' => $v['taxable'], 'contributory' => $v['contributory']]);
        }

        // 2. Contributions and levies.
        $deductible = BigDecimal::zero();
        $deducted = [];

        foreach ($active as $c) {
            if (! in_array($c['kind'], ['contribution', 'levy'], true)) {
                continue;
            }

            $base = $bases[$c['base'] ?? 'gross'] ?? throw new InvalidArgumentException("Unknown base [{$c['base']}] of [{$c['code']}].");
            $base = BigDecimal::max($base, 0);
            [$employee, $employer, $trace] = $this->contribution($c, $base, $scale, $input);

            if (($c['values']['reduces_taxable'] ?? false) === true) {
                $deductible = $deductible->plus($employee);
                $deducted[$c['code']] = $employee;
            }

            $results[] = self::result($c, (string) $base->toScale(0, RoundingMode::HalfUp), (string) $employee, (string) $employer, $trace);
        }

        // 3. Taxable pay, with the limits of pre-tax deductions by category (KE: pension with NSSF 30,000,
        //    post-retirement medical fund 15,000, mortgage interest 30,000 a month): the excess is not deducted.
        $limits = [];
        $excess = BigDecimal::zero();

        foreach ($active as $c) {
            if ($c['kind'] !== 'deduction_limit') {
                continue;
            }

            $claimed = BigDecimal::of($input->preTaxByCategory[$c['category']] ?? '0');

            foreach ($c['includes'] ?? [] as $code) {
                $claimed = $claimed->plus($deducted[$code] ?? BigDecimal::zero());
            }

            $cap = BigDecimal::of($c['values']['cap_minor'])->multipliedBy($scale);
            $over = BigDecimal::max($claimed->minus($cap), 0);
            $excess = $excess->plus($over);
            $limits[] = ['code' => $c['code'], 'claimed_minor' => (string) $claimed->toScale(0, RoundingMode::HalfUp), 'cap_minor' => (string) $cap->toScale(0, RoundingMode::HalfUp), 'not_deducted_minor' => (string) $over->toScale(0, RoundingMode::HalfUp)];
        }

        $taxable = BigDecimal::max($bases['taxable_gross']->minus($input->preTax)->minus($deductible)->plus($excess), 0);
        $bases['taxable_pay'] = $taxable;

        // 4. Income tax, its reductions, cap and reliefs.
        foreach ($active as $c) {
            if ($c['kind'] !== 'income_tax') {
                continue;
            }

            $base = $bases[$c['base'] ?? 'taxable_pay'] ?? throw new InvalidArgumentException("Unknown base [{$c['base']}] of [{$c['code']}].");
            $tax = self::bands($c['values']['bands'], $base, $scale);
            $trace = ['bands_minor' => (string) $tax->toScale(2, RoundingMode::HalfUp), ...($limits === [] ? [] : ['limits' => $limits])];
            $reliefs = [];

            foreach ($active as $r) {
                if ($r['kind'] === 'relief' && $r['applies_to'] === $c['code'] && $r['method'] === 'reduction_per_dependant') {
                    $count = min($input->dependants, (int) $r['values']['max_dependants']);
                    $reduction = $tax->multipliedBy($count)->multipliedBy($r['values']['rate'])->dividedBy(100, self::SCALE, RoundingMode::HalfUp);
                    $tax = $tax->minus($reduction);
                    $reliefs[] = ['code' => $r['code'], 'amount_minor' => (string) $reduction->toScale(0, RoundingMode::HalfUp), 'dependants' => $count];
                }
            }

            if (isset($c['values']['max_rate'])) {
                $cap = $base->multipliedBy($c['values']['max_rate'])->dividedBy(100, self::SCALE, RoundingMode::HalfUp);

                if ($tax->isGreaterThan($cap)) {
                    $trace['capped_minor'] = (string) $cap->toScale(2, RoundingMode::HalfUp);
                    $tax = $cap;
                }
            }

            // CD: a minimum tax on positive taxable pay, never more than the taxable pay itself.
            if (isset($c['values']['min_minor']) && $base->isPositive()) {
                $minimum = BigDecimal::min(BigDecimal::of($c['values']['min_minor'])->multipliedBy($scale), $base);

                if ($tax->isLessThan($minimum)) {
                    $trace['minimum_minor'] = (string) $minimum->toScale(2, RoundingMode::HalfUp);
                    $tax = $minimum;
                }
            }

            foreach ($active as $r) {
                if ($r['kind'] !== 'relief' || $r['applies_to'] !== $c['code'] || $r['method'] === 'reduction_per_dependant') {
                    continue;
                }

                $relief = match ($r['method']) {
                    'relief_fixed' => BigDecimal::of($r['values']['amount_minor'])->multipliedBy($scale),
                    'relief_rate' => BigDecimal::min(
                        ($bases[$r['base'] ?? 'insurance_premiums'] ?? BigDecimal::zero())->multipliedBy($r['values']['rate'])->dividedBy(100, self::SCALE, RoundingMode::HalfUp),
                        BigDecimal::of($r['values']['cap_minor'])->multipliedBy($scale),
                    ),
                    default => throw new InvalidArgumentException("Unknown relief method [{$r['method']}]."),
                };
                $applied = BigDecimal::min($relief, BigDecimal::max($tax, 0));
                $tax = $tax->minus($relief);
                $reliefs[] = ['code' => $r['code'], 'amount_minor' => (string) $applied->toScale(0, RoundingMode::HalfUp)];
            }

            $tax = self::round(BigDecimal::max($tax, 0), $c['values']['rounding']);
            $results[] = self::result($c, (string) $base->toScale(0, RoundingMode::HalfUp), (string) $tax, '0', [...$trace, 'reliefs' => $reliefs]);
        }

        return new StatutoryResult($results, (string) $taxable->toScale(0, RoundingMode::HalfUp), (string) $bases['gross']->toScale(0, RoundingMode::HalfUp));
    }

    /** @return array{0: BigDecimal, 1: BigDecimal, 2: array<string, mixed>} employee and employer shares (rounded) and the trace */
    private function contribution(array $c, BigDecimal $base, BigDecimal $scale, StatutoryInput $input): array
    {
        $v = $c['values'];
        $pct = fn (BigDecimal $amount, string|int $rate) => $amount->multipliedBy($rate)->dividedBy(100, self::SCALE, RoundingMode::HalfUp);

        switch ($c['method']) {
            case 'rate':
                $capped = isset($v['base_cap_minor']) ? BigDecimal::min($base, BigDecimal::of($v['base_cap_minor'])->multipliedBy($scale)) : $base;
                $employee = isset($v['employee_rate']) ? $pct($capped, $v['employee_rate']) : BigDecimal::zero();
                $employer = isset($v['employer_rate']) ? $pct($capped, $v['employer_rate']) : BigDecimal::zero();

                // A floor applies only to pay that has a base (never a deduction from nothing).
                if (isset($v['employee_min_minor'], $v['employee_rate']) && $base->isPositive()) {
                    $employee = BigDecimal::max($employee, BigDecimal::of($v['employee_min_minor'])->multipliedBy($scale));
                }

                if (isset($v['employee_max_minor'], $v['employee_rate'])) {
                    $employee = BigDecimal::min($employee, BigDecimal::of($v['employee_max_minor'])->multipliedBy($scale));
                }

                return [self::round($employee, $v['rounding']), self::round($employer, $v['rounding']), ['capped_base_minor' => (string) $capped->toScale(0, RoundingMode::HalfUp)]];

            case 'tiers':
                $employee = BigDecimal::zero();
                $employer = BigDecimal::zero();
                $previous = BigDecimal::zero();
                $slices = [];

                foreach ($v['tiers'] as $tier) {
                    $upper = $tier['up_to_minor'] === null ? null : BigDecimal::of($tier['up_to_minor'])->multipliedBy($scale);
                    $top = $upper === null ? $base : BigDecimal::min($base, $upper);
                    $slice = BigDecimal::max($top->minus($previous), 0);
                    $employee = $employee->plus($pct($slice, $tier['employee_rate']));
                    $employer = $employer->plus($pct($slice, $tier['employer_rate']));
                    $slices[] = (string) $slice->toScale(2, RoundingMode::HalfUp);

                    if ($upper === null || $base->isLessThanOrEqualTo($upper)) {
                        break;
                    }

                    $previous = $upper;
                }

                return [self::round($employee, $v['rounding']), self::round($employer, $v['rounding']), ['slices_minor' => $slices]];

            case 'fixed':
                $employee = isset($v['employee_minor']) ? BigDecimal::of($v['employee_minor'])->multipliedBy($scale) : BigDecimal::zero();
                $employer = isset($v['employer_minor']) ? BigDecimal::of($v['employer_minor'])->multipliedBy($scale) : BigDecimal::zero();

                return [self::round($employee, $v['rounding']), self::round($employer, $v['rounding']), []];

            case 'headcount_rate':
                $rate = null;

                foreach ($v['brackets'] as $bracket) {
                    if ($bracket['up_to_employees'] === null || $input->headcount <= $bracket['up_to_employees']) {
                        $rate = $bracket['rate'];

                        break;
                    }
                }

                $employer = $rate === null ? BigDecimal::zero() : $pct($base, $rate);

                return [BigDecimal::zero(), self::round($employer, $v['rounding']), ['headcount' => $input->headcount, 'rate' => $rate]];
        }

        throw new InvalidArgumentException("Unknown contribution method [{$c['method']}] of [{$c['code']}].");
    }

    /** Progressive tax of $base by `bands` (limits scaled by $scale), exact. */
    private static function bands(array $bands, BigDecimal $base, BigDecimal $scale): BigDecimal
    {
        $tax = BigDecimal::zero();
        $previous = BigDecimal::zero();

        foreach ($bands as $band) {
            $upper = $band['up_to_minor'] === null ? null : BigDecimal::of($band['up_to_minor'])->multipliedBy($scale);
            $top = $upper === null ? $base : BigDecimal::min($base, $upper);
            $slice = BigDecimal::max($top->minus($previous), 0);
            $tax = $tax->plus($slice->multipliedBy($band['rate'])->dividedBy(100, self::SCALE, RoundingMode::HalfUp));

            if ($upper === null || $base->isLessThanOrEqualTo($upper)) {
                break;
            }

            $previous = $upper;
        }

        return $tax;
    }

    /** $exact rounded once to a multiple of the rule's unit (in minor units), by its mode. */
    public static function round(BigDecimal $exact, array $rule): BigDecimal
    {
        $mode = match ($rule['mode']) {
            'half_up' => RoundingMode::HalfUp,
            'down' => RoundingMode::Down,
            'up' => RoundingMode::Up,
            default => throw new InvalidArgumentException("Unknown rounding mode [{$rule['mode']}]."),
        };
        $unit = BigDecimal::of($rule['unit_minor']);

        return $exact->dividedBy($unit, self::SCALE, RoundingMode::HalfUp)->toScale(0, $mode)->multipliedBy($unit)->toScale(0);
    }

    private static function result(array $c, string $base, string $employee, string $employer, array $trace): array
    {
        return [
            'code' => $c['code'],
            'kind' => $c['kind'],
            'method' => $c['method'],
            'base_minor' => $base,
            'employee_minor' => $employee,
            'employer_minor' => $employer,
            'trace' => $trace,
        ];
    }
}
