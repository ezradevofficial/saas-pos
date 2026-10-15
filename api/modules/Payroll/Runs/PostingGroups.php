<?php

namespace Modules\Payroll\Runs;

use Brick\Math\BigInteger;
use Modules\Payroll\Events\PayrollPosted;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;

/**
 * PAY-22: a posted run as journal groups (decision 8): its lines summed
 * per department and pay currency into `gross`, `net_pay`,
 * `statutory.{code}` (employee shares, income tax included),
 * `employer.{code}`, `deduction.{code}` and `loans`. Statutory allowances
 * are pay (in `gross`), not liabilities. Built from the run's own lines,
 * so a replay raises the same event.
 */
final class PostingGroups
{
    public static function event(Run $run): PayrollPosted
    {
        return new PayrollPosted(
            tenantId: $run->tenant_id,
            runId: $run->id,
            number: $run->number,
            companyId: $run->company_id,
            kind: $run->kind,
            periodStart: $run->period_start->toDateString(),
            periodEnd: $run->period_end->toDateString(),
            payDate: $run->pay_date->toDateString(),
            groups: self::of($run),
        );
    }

    /** @return list<array<string, mixed>> */
    public static function of(Run $run): array
    {
        $groups = [];

        foreach (RunLine::query()->where('run_id', $run->id)->orderBy('number')->get() as $line) {
            $key = ($line->department_id ?? 'none').'|'.$line->pay_currency;
            $groups[$key] ??= [
                'key' => $key,
                'department_id' => $line->department_id,
                'branch_id' => $line->branch_id,
                'currency' => $line->pay_currency,
                'fx' => $line->fx[$line->pay_currency.'/'.$line->base_currency] ?? null,
                'employees' => 0,
                'amounts' => [],
            ];
            $g = &$groups[$key];
            $g['employees']++;

            // Lines of one department at several branches carry no branch.
            if ($g['branch_id'] !== $line->branch_id) {
                $g['branch_id'] = null;
            }

            self::add($g['amounts'], 'gross', $line->gross_minor);
            self::add($g['amounts'], 'net_pay', $line->net_minor);
            self::add($g['amounts'], 'loans', $line->loans_minor);

            foreach ($line->statutory as $component) {
                if ($component['kind'] !== 'allowance') {
                    self::add($g['amounts'], 'statutory.'.$component['code'], $component['employee_minor']);
                }

                self::add($g['amounts'], 'employer.'.$component['code'], $component['employer_minor']);
            }

            foreach ($line->deductions as $deduction) {
                self::add($g['amounts'], 'deduction.'.$deduction['code'], $deduction['amount_minor']);
            }

            unset($g);
        }

        return array_values(array_map(function (array $g) {
            $g['amounts'] = array_filter($g['amounts'], fn (string $minor) => $minor !== '0');
            ksort($g['amounts']);

            return $g;
        }, $groups));
    }

    private static function add(array &$amounts, string $field, int|string $minor): void
    {
        $amounts[$field] = (string) BigInteger::of($amounts[$field] ?? '0')->plus((string) $minor);
    }
}
