<?php

namespace Modules\Payroll\Statutory;

use App\Core\CountryPacks\ResearchStatus;
use Carbon\CarbonImmutable;

/**
 * PAY-08: what a run needs from the country pack and does not have. A
 * component counts when it is required, or conditional (`when`) and used
 * by an employee of the run (insurance premiums, dependants, children).
 * It is missing a value when no period is in force on the pay date, when
 * its period still `needs_confirmation`, or when a value its method needs
 * is null, absent or malformed. Weekly and bi-weekly runs also need the
 * pack's `non_monthly` rule. Values are never guessed.
 *
 * CP-01: a period is usable when confirmed or `researched` at high or
 * medium confidence (ResearchStatus); low-confidence research is never
 * usable. A component the company marked not applicable (`may_not_apply`
 * in the pack, payroll settings) is not checked, and one with
 * `unconfirmed: not_applied` is left out while unusable instead of
 * blocking the run (PayrollPack::applicable).
 */
final class MissingValues
{
    /** method => [values it always needs, values it may have]. */
    public const METHODS = [
        'bands' => [['bands', 'rounding'], ['max_rate', 'min_minor']],
        'rate' => [['rounding'], ['employee_rate', 'employer_rate', 'base_cap_minor', 'employee_min_minor', 'employee_max_minor', 'reduces_taxable']],
        'tiers' => [['tiers', 'rounding', 'reduces_taxable'], []],
        'fixed' => [['rounding'], ['employee_minor', 'employer_minor', 'reduces_taxable']],
        'headcount_rate' => [['brackets', 'rounding'], []],
        'relief_fixed' => [['amount_minor'], []],
        'relief_rate' => [['rate', 'cap_minor'], []],
        'reduction_per_dependant' => [['rate', 'max_dependants'], []],
        'allowance_per_child' => [['amount_minor', 'taxable', 'contributory', 'rounding'], []],
        'deduction_cap' => [['cap_minor'], []],
    ];

    public const MODES = ['half_up', 'down', 'up'];

    /**
     * @param  array<string, bool>  $used  conditions an employee of the run meets (insurance_premiums, dependants, children, pre-tax categories)
     * @param  list<string>  $notApplicable  components the company marked not applicable
     * @return list<array{component: string, fields: list<string>}>
     */
    public static function check(array $pack, CarbonImmutable $payDate, string $frequency, array $used = [], array $notApplicable = []): array
    {
        $missing = [];

        if ($frequency !== 'monthly' && ($pack['non_monthly'] ?? null) !== 'pro_rata') {
            $missing[] = ['component' => 'pack', 'fields' => ['non_monthly']];
        }

        foreach (PayrollPack::applicable(PayrollPack::inForce($pack, $payDate), $notApplicable) as $component) {
            if (! $component['required'] && ($component['when'] === null || ! ($used[$component['when']] ?? false))) {
                continue;
            }

            $fields = self::fields($component);

            if ($fields !== []) {
                $missing[] = ['component' => $component['code'], 'fields' => $fields];
            }
        }

        return $missing;
    }

    /**
     * The values one component lacks (`period` when none is in force,
     * `needs_confirmation` while its period is unconfirmed).
     *
     * @return list<string>
     */
    public static function fields(array $component): array
    {
        $values = $component['values'];

        if ($component['overlapping'] ?? false) {
            return ['periods_overlap'];
        }

        if ($values === null) {
            return ['period'];
        }

        [$required, $optional] = self::METHODS[$component['method']] ?? [null, null];

        if ($required === null) {
            return ['method'];
        }

        $fields = [];

        if (! ResearchStatus::usable($values)) {
            $fields[] = 'needs_confirmation';
        }

        foreach ($required as $field) {
            if (! array_key_exists($field, $values) || ! self::valid($field, $values[$field])) {
                $fields[] = $field;
            }
        }

        foreach ($optional as $field) {
            if (array_key_exists($field, $values) && ! self::valid($field, $values[$field])) {
                $fields[] = $field;
            }
        }

        // A share needs its rate; an employee share needs to say whether it reduces taxable pay.
        if ($component['method'] === 'rate' && ! array_key_exists('employee_rate', $values) && ! array_key_exists('employer_rate', $values)) {
            $fields[] = 'employee_rate';
        }

        if ($component['method'] === 'fixed' && ! array_key_exists('employee_minor', $values) && ! array_key_exists('employer_minor', $values)) {
            $fields[] = 'employer_minor';
        }

        if (in_array($component['method'], ['rate', 'fixed'], true) && (array_key_exists('employee_rate', $values) || array_key_exists('employee_minor', $values))
            && ! array_key_exists('reduces_taxable', $values)) {
            $fields[] = 'reduces_taxable';
        }

        return array_values(array_unique($fields));
    }

    private static function valid(string $field, mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            $field === 'rounding' => is_array($value) && in_array($value['mode'] ?? null, self::MODES, true) && self::minor($value['unit_minor'] ?? null) && (string) $value['unit_minor'] !== '0',
            $field === 'bands' => self::steps($value, ['rate']),
            $field === 'tiers' => self::steps($value, ['employee_rate', 'employer_rate']),
            $field === 'brackets' => self::brackets($value),
            in_array($field, ['reduces_taxable', 'taxable', 'contributory'], true) => is_bool($value),
            $field === 'max_dependants' => is_int($value) && $value >= 0,
            str_ends_with($field, '_minor') => self::minor($value),
            default => self::rate($value),
        };
    }

    /** Ascending `up_to_minor` limits (only the last may be null), each with its rates. */
    private static function steps(mixed $value, array $rates): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        $previous = -1;

        foreach ($value as $i => $step) {
            if (! is_array($step)) {
                return false;
            }

            foreach ($rates as $rate) {
                if (! self::rate($step[$rate] ?? null)) {
                    return false;
                }
            }

            $limit = $step['up_to_minor'] ?? null;

            if ($limit === null) {
                if ($i !== count($value) - 1) {
                    return false;
                }

                continue;
            }

            if (! self::minor($limit) || (int) $limit <= $previous) {
                return false;
            }

            $previous = (int) $limit;
        }

        return true;
    }

    private static function brackets(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        $previous = -1;

        foreach ($value as $i => $bracket) {
            if (! is_array($bracket) || ! self::rate($bracket['rate'] ?? null)) {
                return false;
            }

            $limit = $bracket['up_to_employees'] ?? null;

            if ($limit === null) {
                if ($i !== count($value) - 1) {
                    return false;
                }

                continue;
            }

            if (! is_int($limit) || $limit <= $previous) {
                return false;
            }

            $previous = $limit;
        }

        return true;
    }

    private static function minor(mixed $value): bool
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^\d{1,18}\z/', $value) === 1);
    }

    /** A percentage: a non-negative decimal string or integer (never a JSON float, which loses precision). */
    private static function rate(mixed $value): bool
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^\d{1,3}(\.\d{1,6})?\z/', $value) === 1);
    }

    /**
     * The missing values worded for people: "PAYE: bands, rounding; NSSF: tiers".
     *
     * @param  list<array{component: string, fields: list<string>}>  $missing
     */
    public static function describe(array $missing): string
    {
        return implode('; ', array_map(
            fn (array $m) => PayrollPack::label('components', $m['component']).': '.implode(', ', array_map(fn (string $f) => PayrollPack::label('fields', $f), $m['fields'])),
            $missing,
        ));
    }
}
