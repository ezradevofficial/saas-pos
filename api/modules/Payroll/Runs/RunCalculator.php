<?php

namespace Modules\Payroll\Runs;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Payroll\Employees\EmployeeCopy;
use Modules\Payroll\Models\Attendance;
use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Models\Item;
use Modules\Payroll\Models\LeaveDay;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\PayrollContract;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Models\Settings;
use Modules\Payroll\Statutory\MissingValues;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Statutory\StatutoryEngine;
use Modules\Payroll\Statutory\StatutoryInput;
use Modules\Payroll\Support\Errors;

/**
 * PAY-09 to PAY-15, PAY-18: a run's lines, one per employee of its pay
 * group employed in the period (not on hold), recomputed from Payroll's
 * own copy of HR's data (refreshed first) and its own setup:
 *
 * 1. Gross in the pay currency: the basic from the contracts in force
 *    (monthly pay pro-rated by calendar days employed; daily and hourly
 *    pay from closed attendance), unpaid leave at the daily rate,
 *    overtime and premiums at the company's multipliers, and the
 *    employee's items. Contract amounts in another currency are
 *    converted at the core rate on the pay date (CUR-04).
 * 2. The pack is checked (MissingValues): any value missing refuses the
 *    whole run (422 `statutory_rates_missing`, PAY-08).
 * 3. The statutory engine runs in the pack currency; its results come
 *    back into the pay currency; loans are deducted; net = gross −
 *    statutory − deductions − loans. A negative net, a missing rate or a
 *    currency that does not match is a line error that blocks submission.
 *
 * A correction (PAY-18) has, per chosen employee, the difference between
 * what they should have had (`recalculate`: the same computation, the
 * period's paid attendance and one-offs included, loans as posted) or
 * nothing (`reverse`) and what the posted runs of the period gave them.
 */
class RunCalculator
{
    public function __construct(
        private readonly PayrollPack $packs,
        private readonly StatutoryEngine $engine,
        private readonly ExchangeRates $rates,
        private readonly CurrencyDecimals $decimals,
        private readonly EmployeeCopy $copy,
    ) {}

    public function calculate(Run $run): void
    {
        $company = Company::query()->findOrFail($run->company_id);
        $group = PayGroup::query()->findOrFail($run->pay_group_id);
        $pack = $this->packs->of($company->country);

        if ($pack === null) {
            throw Errors::make(422, 'payroll_pack_missing', ['country' => (string) $company->country]);
        }

        // PAY-05: refresh the copy from HR first (a module switched on after HR's records exist pays from them).
        $this->copy->syncCompany($company->id, $run->id);

        $context = new RunContext($run, $company, $group, $pack, Settings::query()->where('company_id', $company->id)->first(), $this->at($run, $company));
        $employees = $this->employees($run, $group);
        $prepared = [];

        foreach ($employees as $employee) {
            $prepared[$employee->id] = $run->kind === Run::CORRECTION && $run->correction_mode === Run::REVERSE
                ? null
                : $this->prepare($context, $employee);

            // PAY-18: a recalculation keeps the loans as posted (their instalments are not recomputed).
            if ($prepared[$employee->id] !== null && $run->kind === Run::CORRECTION) {
                $prepared[$employee->id]['loans'] = $this->posted($run, $employee)['loans'];
            }
        }

        // PAY-08: the pack values every employee of the run needs.
        $used = ['insurance_premiums' => false, 'dependants' => false, 'children' => false];

        foreach ($prepared as $id => $p) {
            if ($p !== null) {
                $used['insurance_premiums'] = $used['insurance_premiums'] || $p['insurance_minor'] > 0;
                $used['dependants'] = $used['dependants'] || $employees[$id]->dependants > 0;
                $used['children'] = $used['children'] || $employees[$id]->children > 0;
            }
        }

        $missing = array_filter($prepared) === [] ? [] : MissingValues::check($pack, $run->pay_date, $group->frequency, $used);

        if ($missing !== []) {
            $run->fill(['missing' => $missing])->save();

            throw Errors::make(422, 'statutory_rates_missing', ['country' => (string) $company->country, 'missing' => MissingValues::describe($missing)], extra: ['missing' => $missing]);
        }

        $components = PayrollPack::inForce($pack, $run->pay_date);
        $headcount = $this->headcount($company->id, $run->period_end);
        $lines = [];

        foreach ($employees as $employee) {
            $line = $prepared[$employee->id] === null ? $this->zero($context, $employee) : $this->finish($context, $employee, $prepared[$employee->id], $components, $headcount);

            if ($run->kind === Run::CORRECTION) {
                $line = $this->difference($line, $this->posted($run, $employee));
            }

            $lines[] = $line;
        }

        $previous = $this->previousLines($run);
        RunLine::query()->where('run_id', $run->id)->delete();

        foreach ($lines as $line) {
            $line['variance'] = $this->variance($line, $previous[$line['payroll_employee_id']] ?? null);
            RunLine::query()->create($line);
        }

        $run->fill(['missing' => null, 'totals' => $this->totals($run, $lines, $previous, $employees)])->save();
    }

    /** @return Collection<string, PayrollEmployee> by id */
    private function employees(Run $run, PayGroup $group): Collection
    {
        $query = PayrollEmployee::query()->where('pay_group_id', $group->id)->where('company_id', $run->company_id)
            ->whereDate('hire_date', '<=', $run->period_end)
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $run->period_start));

        if ($run->kind === Run::CORRECTION) {
            // PAY-18: the chosen employees paid by the period's posted runs, held or not.
            $paid = RunLine::query()->whereIn('run_id', $this->postedRunIds($run))->pluck('payroll_employee_id')->unique()->all();
            $query = PayrollEmployee::query()->whereIn('id', array_values(array_intersect($paid, $run->correction_employee_ids ?? $paid)));
        } else {
            $query->where('on_hold', false);
        }

        return $query->orderBy('number')->get()->keyBy('id');
    }

    /**
     * Step 1: one employee's gross, deductions and loans in the pay
     * currency, and their bases for the engine.
     *
     * @return array<string, mixed>
     */
    private function prepare(RunContext $context, PayrollEmployee $employee): array
    {
        $run = $context->run;
        $errors = [];
        $warnings = [];
        $fx = [];
        $earnings = [];
        $deductions = [];
        $inputs = ['item_ids' => [], 'attendance_ids' => [], 'leave_days' => '0', 'days' => []];

        $contracts = PayrollContract::query()->where('employee_id', $employee->employee_id)
            ->whereDate('start_date', '<=', $run->period_end)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $run->period_start))
            ->orderBy('start_date')->get();
        $last = $contracts->last();
        $payCurrency = $employee->pay_currency ?? $last?->currency ?? $context->company->base_currency;

        if ($contracts->isEmpty()) {
            $warnings[] = 'no_contract';
        }

        // Converts a contract amount into the pay currency at the run's rate (CUR-04).
        $toPay = function (BigDecimal $minor, string $from) use ($context, $payCurrency, &$fx, &$errors): BigDecimal {
            if ($from === $payCurrency) {
                return $minor;
            }

            $snapshot = $this->snapshot($context, $from, $payCurrency, $fx, $errors);

            return $snapshot === null ? BigDecimal::zero() : $this->exactConvert($minor, $from, $payCurrency, $snapshot);
        };

        // Attendance of the period: closed HR periods ending in it, not paid yet (with the ones this period's posted runs paid, for a correction).
        $attendance = $this->attendance($run, $employee);
        $inputs['attendance_ids'] = $attendance->pluck('id')->all();
        $minutes = fn (string $field) => (int) $attendance->sum($field);
        $periodDays = $run->period_start->diffInDays($run->period_end) + 1;
        $share = BigDecimal::of(12)->dividedBy($context->group->periodsPerYear(), 12, RoundingMode::HalfUp);
        $basic = BigDecimal::zero();
        $hourly = null;
        $daily = null;

        foreach ($contracts as $contract) {
            $pay = $toPay(BigDecimal::of($contract->pay_minor), $contract->currency);
            $from = CarbonImmutable::parse(max($contract->start_date->toDateString(), $run->period_start->toDateString(), $employee->hire_date->toDateString()));
            $to = CarbonImmutable::parse(min($contract->end_date?->toDateString() ?? '9999-12-31', $run->period_end->toDateString(), $employee->termination_date?->toDateString() ?? '9999-12-31'));
            $days = $from->lte($to) ? $from->diffInDays($to) + 1 : 0;
            $inputs['days'][] = ['contract_id' => $contract->contract_id, 'basis' => $contract->pay_basis, 'days' => $days, 'period_days' => $periodDays];

            if ($contract->pay_basis === 'monthly') {
                // PAY-09: pro-rated by calendar days employed in the period (decision 4).
                $basic = $basic->plus($pay->multipliedBy($share)->multipliedBy($days)->dividedBy($periodDays, 12, RoundingMode::HalfUp));
            }

            if ($contract === $last) {
                [$hourly, $daily] = $this->rates($contract, $pay, $context->settings);
            }
        }

        if ($last !== null && $last->pay_basis !== 'monthly') {
            if ($attendance->isEmpty()) {
                $warnings[] = 'no_attendance';
            } elseif ($last->pay_basis === 'daily') {
                $basic = $basic->plus($toPay(BigDecimal::of($last->pay_minor), $last->currency)->multipliedBy($minutes('days_present')));
            } else {
                $basic = $basic->plus($toPay(BigDecimal::of($last->pay_minor), $last->currency)->multipliedBy($minutes('regular_minutes'))->dividedBy(60, 12, RoundingMode::HalfUp));
            }
        }

        $basicMinor = self::minor($basic);
        $earnings[] = ['code' => 'basic', 'name' => __('payroll.lines.basic'), 'source' => 'basic', 'amount_minor' => $basicMinor, 'taxable' => true, 'contributory' => true];

        // PAY-11: unpaid leave of monthly pay at the daily rate.
        if ($last?->pay_basis === 'monthly') {
            $leave = LeaveDay::query()->where('employee_id', $employee->employee_id)->whereNull('cancelled_at')
                ->whereDate('date', '>=', $run->period_start)->whereDate('date', '<=', $run->period_end)->get();
            $leaveDays = $leave->reduce(fn (BigDecimal $sum, LeaveDay $d) => $sum->plus($d->fraction), BigDecimal::zero());

            if ($leaveDays->isPositive()) {
                $inputs['leave_days'] = (string) $leaveDays;

                if ($daily === null) {
                    $errors[] = 'monthly_days_missing';
                } else {
                    $earnings[] = ['code' => 'unpaid_leave', 'name' => __('payroll.lines.unpaid_leave'), 'source' => 'leave', 'amount_minor' => self::minor($daily->multipliedBy($leaveDays)->negated()), 'taxable' => true, 'contributory' => true, 'quantity' => (string) $leaveDays];
                }
            }
        }

        // PAY-12: overtime and premiums at the company's multipliers (decision 5).
        $premiums = [
            'overtime' => ['overtime_minutes', 'overtime_multiplier', false],
            'night' => ['night_minutes', 'night_multiplier', true],
            'holiday' => ['holiday_minutes', 'holiday_multiplier', true],
            'rest_day' => ['rest_day_minutes', 'rest_day_multiplier', true],
        ];

        foreach ($premiums as $code => [$field, $setting, $onTop]) {
            $count = $minutes($field);

            if ($count <= 0) {
                continue;
            }

            $multiplier = $context->settings?->{$setting};

            if ($multiplier === null) {
                $warnings[] = "{$code}_not_paid";

                continue;
            }

            if ($hourly === null) {
                $errors[] = 'monthly_hours_missing';

                continue;
            }

            $factor = BigDecimal::of($multiplier)->minus($onTop ? 1 : 0);
            $amount = $hourly->multipliedBy($count)->dividedBy(60, 12, RoundingMode::HalfUp)->multipliedBy($factor);
            $earnings[] = [
                'code' => $code, 'name' => __("payroll.lines.{$code}"), 'source' => 'attendance', 'amount_minor' => self::minor($amount),
                'taxable' => (bool) ($context->settings->premiums_taxable ?? true), 'contributory' => (bool) ($context->settings->premiums_contributory ?? true),
                'quantity' => (string) BigDecimal::of($count)->dividedBy(60, 2, RoundingMode::HalfUp), 'multiplier' => rtrim(rtrim((string) $multiplier, '0'), '.'),
            ];
        }

        // PAY-06: the employee's recurring items and this period's one-offs.
        foreach ($this->items($run, $employee) as $entry) {
            $item = $entry->item;

            if ($entry->currency !== $payCurrency) {
                $errors[] = 'currency_mismatch';

                continue;
            }

            $inputs['item_ids'][] = $entry->id;
            $row = ['code' => $item->code, 'name' => $item->name, 'source' => 'item', 'amount_minor' => (string) $entry->amount_minor];

            if ($item->kind === Item::EARNING) {
                $earnings[] = [...$row, 'taxable' => $item->taxable, 'contributory' => $item->contributory];
            } else {
                $deductions[] = [...$row, 'pre_tax' => $item->pre_tax, 'insurance_premium' => $item->insurance_premium];
            }
        }

        // PAY-13: loan instalments (a correction keeps what was posted).
        $loans = [];

        if ($run->kind === Run::REGULAR) {
            foreach (Loan::query()->where('payroll_employee_id', $employee->id)->where('status', Loan::ACTIVE)->whereDate('starts_on', '<=', $run->period_end)->orderBy('starts_on')->orderBy('id')->get() as $loan) {
                $balance = $loan->balance();

                if ($balance <= 0) {
                    continue;
                }

                if ($loan->currency !== $payCurrency) {
                    $errors[] = 'currency_mismatch';

                    continue;
                }

                $amount = $loan->settle_next_run ? $balance : min($loan->instalment_minor, $balance);
                $loans[] = ['loan_id' => $loan->id, 'kind' => $loan->kind, 'reference' => $loan->reference, 'amount_minor' => (string) $amount, 'balance_before_minor' => (string) $balance];
            }
        }

        $earnings = self::merge($earnings, 'code');
        $deductions = self::merge($deductions, 'code');
        $sum = fn (array $rows, ?string $flag = null) => (string) array_reduce($rows, fn (BigInteger $s, array $r) => $flag === null || ($r[$flag] ?? false) ? $s->plus($r['amount_minor']) : $s, BigInteger::zero());

        return [
            'pay_currency' => $payCurrency,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'loans' => $loans,
            'basic_minor' => $basicMinor,
            'gross_minor' => $sum($earnings),
            'taxable_minor' => $sum($earnings, 'taxable'),
            'contributory_minor' => $sum($earnings, 'contributory'),
            'pre_tax_minor' => $sum($deductions, 'pre_tax'),
            'insurance_minor' => (int) $sum($deductions, 'insurance_premium'),
            'fx' => $fx,
            'errors' => $errors,
            'warnings' => $warnings,
            'inputs' => [...$inputs, 'hourly_rate_minor' => $hourly === null ? null : (string) $hourly->toScale(4, RoundingMode::HalfUp), 'daily_rate_minor' => $daily === null ? null : (string) $daily->toScale(4, RoundingMode::HalfUp)],
        ];
    }

    /**
     * Step 3: the statutory engine in the pack currency, back into the pay
     * currency, then net pay and base amounts.
     *
     * @return array<string, mixed> the run line's attributes
     */
    private function finish(RunContext $context, PayrollEmployee $employee, array $p, array $components, int $headcount): array
    {
        $run = $context->run;
        $payCurrency = $p['pay_currency'];
        $statutoryCurrency = (string) $context->pack['currency'];
        $fx = $p['fx'];
        $errors = $p['errors'];
        $toStatutory = $payCurrency === $statutoryCurrency ? null : $this->snapshot($context, $payCurrency, $statutoryCurrency, $fx, $errors);
        $in = fn (string $minor) => $toStatutory === null ? $minor : $this->convert($minor, $payCurrency, $statutoryCurrency, $toStatutory);
        $out = fn (string $minor) => $toStatutory === null ? $minor : $this->convert($minor, $statutoryCurrency, $payCurrency, $toStatutory);

        $scale = $context->group->frequency === PayGroup::MONTHLY ? '1'
            : (string) BigDecimal::of(12)->dividedBy($context->group->periodsPerYear(), 12, RoundingMode::HalfUp);

        $result = $this->engine->compute($components, new StatutoryInput(
            gross: $in($p['gross_minor']),
            taxableGross: $in($p['taxable_minor']),
            contributoryGross: $in($p['contributory_minor']),
            basic: $in($p['basic_minor']),
            preTax: $in($p['pre_tax_minor']),
            insurancePremiums: $in((string) $p['insurance_minor']),
            dependants: $employee->dependants,
            children: $employee->children,
            headcount: $headcount,
            scale: $scale,
        ));

        $earnings = $p['earnings'];
        $statutory = [];
        $statutoryTotal = BigInteger::zero();
        $employerTotal = BigInteger::zero();

        foreach ($result->components as $c) {
            $employeePay = $out($c['employee_minor']);
            $employerPay = $out($c['employer_minor']);

            if ($c['kind'] === 'allowance') {
                // A statutory allowance is pay: it joins the earnings (its flags from the pack).
                if ($employeePay !== '0') {
                    $earnings[] = ['code' => $c['code'], 'name' => PayrollPack::label('components', $c['code']), 'source' => 'statutory', 'amount_minor' => $employeePay,
                        'taxable' => (bool) ($c['trace']['taxable'] ?? false), 'contributory' => (bool) ($c['trace']['contributory'] ?? false)];
                }
            } else {
                $statutoryTotal = $statutoryTotal->plus($employeePay);
            }

            $employerTotal = $employerTotal->plus($employerPay);
            $statutory[] = [
                'code' => $c['code'], 'kind' => $c['kind'], 'name' => PayrollPack::label('components', $c['code']),
                'employee_minor' => $employeePay, 'employer_minor' => $employerPay,
                'employee_statutory_minor' => $c['employee_minor'], 'employer_statutory_minor' => $c['employer_minor'], 'base_statutory_minor' => $c['base_minor'],
                'trace' => $c['trace'],
            ];
        }

        $gross = array_reduce($earnings, fn (BigInteger $s, array $r) => $s->plus($r['amount_minor']), BigInteger::zero());
        $deductions = array_reduce($p['deductions'], fn (BigInteger $s, array $r) => $s->plus($r['amount_minor']), BigInteger::zero());
        $loans = array_reduce($p['loans'], fn (BigInteger $s, array $r) => $s->plus($r['amount_minor']), BigInteger::zero());
        $net = $gross->minus($statutoryTotal)->minus($deductions)->minus($loans);

        if ($net->isNegative()) {
            $errors[] = 'net_negative';
        }

        $line = $this->base($context, $employee, $payCurrency, $statutoryCurrency);
        $toBase = $payCurrency === $context->company->base_currency ? null : $this->snapshot($context, $payCurrency, $context->company->base_currency, $fx, $errors);
        $base = fn (BigInteger $minor) => $toBase === null ? (string) $minor : $this->convert((string) $minor, $payCurrency, $context->company->base_currency, $toBase);

        return [
            ...$line,
            'fx' => $fx,
            'basic_minor' => (int) $p['basic_minor'],
            'gross_minor' => (string) $gross,
            'gross_statutory_minor' => $result->gross,
            'taxable_statutory_minor' => $result->taxablePay,
            'statutory_minor' => (string) $statutoryTotal,
            'deductions_minor' => (string) $deductions,
            'loans_minor' => (string) $loans,
            'net_minor' => (string) $net,
            'employer_minor' => (string) $employerTotal,
            'gross_base_minor' => $base($gross),
            'net_base_minor' => $base($net),
            'employer_base_minor' => $base($employerTotal),
            'earnings' => $earnings,
            'deductions' => $p['deductions'],
            'statutory' => $statutory,
            'loans' => $p['loans'],
            'inputs' => [...$p['inputs'], 'dependants' => $employee->dependants, 'children' => $employee->children, 'headcount' => $headcount, 'scale' => $scale],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($p['warnings'])),
        ];
    }

    /** A reversal line's starting point: nothing (the difference with what was posted is its negation). */
    private function zero(RunContext $context, PayrollEmployee $employee): array
    {
        $posted = RunLine::query()->whereIn('run_id', $this->postedRunIds($context->run))->where('payroll_employee_id', $employee->id)->orderBy('created_at')->first();
        $payCurrency = $posted?->pay_currency ?? $employee->pay_currency ?? $context->company->base_currency;

        return [
            ...$this->base($context, $employee, $payCurrency, $posted?->statutory_currency ?? ($context->pack['currency'] ?? null)),
            'fx' => $posted?->fx ?? [],
            ...array_fill_keys(RunLine::MONEY, '0'),
            'earnings' => [], 'deductions' => [], 'statutory' => [], 'loans' => [],
            'inputs' => ['item_ids' => [], 'attendance_ids' => []],
            'errors' => [], 'warnings' => [],
        ];
    }

    /** @return array<string, mixed> the line's identity and snapshot of the employee */
    private function base(RunContext $context, PayrollEmployee $employee, string $payCurrency, ?string $statutoryCurrency): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'run_id' => $context->run->id,
            'payroll_employee_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'company_id' => $employee->company_id,
            'branch_id' => $employee->branch_id,
            'location_id' => $employee->location_id,
            'department_id' => $employee->department_id,
            'number' => $employee->number,
            'name' => $employee->name,
            'pay_currency' => $payCurrency,
            'statutory_currency' => $statutoryCurrency,
            'base_currency' => $context->company->base_currency,
        ];
    }

    /**
     * PAY-18: $line minus what the period's posted runs gave the employee,
     * component by component (codes, loans by loan), so a correction's
     * lines, payslips and journals carry the signed differences.
     */
    private function difference(array $line, array $posted): array
    {
        foreach (RunLine::MONEY as $field) {
            $line[$field] = (string) BigInteger::of((string) $line[$field])->minus($posted[$field]);
        }

        // A recalculation carries the posted loans (no difference); a reversal gives them back.
        $line['loans'] = self::diff($line['loans'], $posted['loans'], 'loan_id', ['amount_minor']);
        $line['earnings'] = self::diff($line['earnings'], $posted['earnings'], 'code', ['amount_minor']);
        $line['deductions'] = self::diff($line['deductions'], $posted['deductions'], 'code', ['amount_minor']);
        $line['statutory'] = self::diff($line['statutory'], $posted['statutory'], 'code', ['employee_minor', 'employer_minor', 'employee_statutory_minor', 'employer_statutory_minor']);
        $line['inputs'] = [...$line['inputs'], 'corrects' => $posted['run_ids']];
        $line['errors'] = array_values(array_diff($line['errors'], ['net_negative']));

        return $line;
    }

    /** @return array<string, mixed> the sums of the employee's lines in the period's posted runs */
    private function posted(Run $run, PayrollEmployee $employee): array
    {
        $lines = RunLine::query()->whereIn('run_id', $this->postedRunIds($run))->where('payroll_employee_id', $employee->id)->get();
        $sums = array_fill_keys(RunLine::MONEY, BigInteger::zero());
        $earnings = $deductions = $statutory = $loans = [];

        foreach ($lines as $line) {
            foreach (RunLine::MONEY as $field) {
                $sums[$field] = $sums[$field]->plus((int) $line->{$field});
            }

            $earnings = self::diff($earnings, self::negate($line->earnings, ['amount_minor']), 'code', ['amount_minor']);
            $deductions = self::diff($deductions, self::negate($line->deductions, ['amount_minor']), 'code', ['amount_minor']);
            $statutory = self::diff($statutory, self::negate($line->statutory, ['employee_minor', 'employer_minor', 'employee_statutory_minor', 'employer_statutory_minor']), 'code', ['employee_minor', 'employer_minor', 'employee_statutory_minor', 'employer_statutory_minor']);
            $loans = self::diff($loans, self::negate($line->loans, ['amount_minor']), 'loan_id', ['amount_minor']);
        }

        return [...array_map('strval', $sums), 'earnings' => $earnings, 'deductions' => $deductions, 'statutory' => $statutory, 'loans' => $loans, 'run_ids' => $lines->pluck('run_id')->unique()->values()->all()];
    }

    /** @return list<string> the posted runs of $run's pay group and period (the original and its corrections) */
    private function postedRunIds(Run $run): array
    {
        return Run::query()->where('pay_group_id', $run->pay_group_id)->whereDate('period_start', $run->period_start)
            ->where('status', Run::POSTED)->pluck('id')->all();
    }

    /** $a − $b by $key, on $fields (other values from whichever has the row); rows that end at zero are dropped. */
    private static function diff(array $a, array $b, string $key, array $fields): array
    {
        $rows = [];

        foreach ($a as $row) {
            $rows[$row[$key]] = $row;
        }

        foreach ($b as $row) {
            $k = $row[$key];
            $current = $rows[$k] ?? [...$row, ...array_fill_keys($fields, '0')];

            foreach ($fields as $field) {
                $current[$field] = (string) BigInteger::of((string) ($current[$field] ?? '0'))->minus((string) ($row[$field] ?? '0'));
            }

            $rows[$k] = $current;
        }

        return array_values(array_filter($rows, function (array $row) use ($fields) {
            foreach ($fields as $field) {
                if ((string) ($row[$field] ?? '0') !== '0') {
                    return true;
                }
            }

            return false;
        }));
    }

    private static function negate(array $rows, array $fields): array
    {
        return array_map(function (array $row) use ($fields) {
            foreach ($fields as $field) {
                $row[$field] = (string) BigInteger::of((string) ($row[$field] ?? '0'))->negated();
            }

            return $row;
        }, $rows);
    }

    /** Rows with the same $key summed (amounts as digit strings). */
    private static function merge(array $rows, string $key): array
    {
        $merged = [];

        foreach ($rows as $row) {
            if (isset($merged[$row[$key]])) {
                $merged[$row[$key]]['amount_minor'] = (string) BigInteger::of($merged[$row[$key]]['amount_minor'])->plus($row['amount_minor']);
            } else {
                $merged[$row[$key]] = $row;
            }
        }

        return array_values($merged);
    }

    /**
     * The hourly and daily rate of a contract in the pay currency (exact):
     * monthly pay ÷ monthly hours (the contract's weekly hours × 52 ÷ 12,
     * else the company's standard) and ÷ standard monthly days; daily pay
     * ÷ (monthly hours ÷ monthly days) an hour; hourly pay is the rate.
     *
     * @return array{0: ?BigDecimal, 1: ?BigDecimal}
     */
    private function rates(PayrollContract $contract, BigDecimal $pay, ?Settings $settings): array
    {
        $hours = $contract->hours_per_week !== null && BigDecimal::of($contract->hours_per_week)->isPositive()
            ? BigDecimal::of($contract->hours_per_week)->multipliedBy(52)->dividedBy(12, 12, RoundingMode::HalfUp)
            : ($settings?->monthly_hours === null ? null : BigDecimal::of($settings->monthly_hours));
        $days = $settings?->monthly_days === null ? null : BigDecimal::of($settings->monthly_days);

        return match ($contract->pay_basis) {
            'monthly' => [
                $hours === null ? null : $pay->dividedBy($hours, 12, RoundingMode::HalfUp),
                $days === null ? null : $pay->dividedBy($days, 12, RoundingMode::HalfUp),
            ],
            'daily' => [$hours === null || $days === null ? null : $pay->multipliedBy($days)->dividedBy($hours, 12, RoundingMode::HalfUp), $pay],
            default => [$pay, null],
        };
    }

    /** @return Collection<int, Attendance> */
    private function attendance(Run $run, PayrollEmployee $employee): Collection
    {
        $posted = $run->kind === Run::CORRECTION ? $this->postedRunIds($run) : [];

        return Attendance::query()->where('employee_id', $employee->employee_id)
            ->whereDate('end_date', '>=', $run->period_start)->whereDate('end_date', '<=', $run->period_end)
            ->where(fn ($q) => $q->whereNull('paid_run_id')->orWhereIn('paid_run_id', $posted))
            ->orderBy('end_date')->get();
    }

    /** @return Collection<int, EmployeeItem> */
    private function items(Run $run, PayrollEmployee $employee): Collection
    {
        $posted = $run->kind === Run::CORRECTION ? $this->postedRunIds($run) : [];

        return EmployeeItem::query()->with('item')->where('payroll_employee_id', $employee->id)->whereNull('archived_at')
            ->where(function ($q) use ($run, $posted) {
                $q->where(fn ($r) => $r->where('recurrence', EmployeeItem::RECURRING)->whereDate('from_date', '<=', $run->period_end)
                    ->where(fn ($t) => $t->whereNull('to_date')->orWhereDate('to_date', '>=', $run->period_start)))
                    ->orWhere(fn ($o) => $o->where('recurrence', EmployeeItem::ONCE)->whereDate('period_date', '>=', $run->period_start)->whereDate('period_date', '<=', $run->period_end)
                        ->where(fn ($c) => $c->whereNull('consumed_run_id')->orWhereIn('consumed_run_id', $posted)));
            })
            ->orderBy('created_at')->get();
    }

    /** INPP-like levies: the company's employees employed on the period's last day. */
    private function headcount(string $companyId, CarbonImmutable $on): int
    {
        return max(1, PayrollEmployee::query()->where('company_id', $companyId)->whereDate('hire_date', '<=', $on)
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $on))->count());
    }

    /** @return array<string, array{gross_minor: int, net_minor: int}> the previous posted regular run's lines by employee */
    private function previousLines(Run $run): array
    {
        $previous = Run::query()->where('pay_group_id', $run->pay_group_id)->where('kind', Run::REGULAR)->where('status', Run::POSTED)
            ->whereDate('period_start', '<', $run->period_start)->orderByDesc('period_start')->first();

        if ($previous === null || $run->kind !== Run::REGULAR) {
            return [];
        }

        return RunLine::query()->where('run_id', $previous->id)->get()
            ->mapWithKeys(fn (RunLine $l) => [$l->payroll_employee_id => ['run_id' => $previous->id, 'number' => $l->number, 'name' => $l->name, 'currency' => $l->pay_currency, 'gross_minor' => $l->gross_minor, 'net_minor' => $l->net_minor]])->all();
    }

    /** PAY-15: the line against the employee's line of the previous posted run. */
    private function variance(array $line, ?array $previous): ?array
    {
        if ($previous === null) {
            return $line['inputs']['corrects'] ?? null ? null : ['status' => 'new'];
        }

        $pct = fn (string $now, int $before) => $before === 0 ? null : (string) BigDecimal::of($now)->minus($before)->multipliedBy(100)->dividedBy($before, 2, RoundingMode::HalfUp);

        return [
            'status' => 'changed',
            'previous_gross_minor' => (string) $previous['gross_minor'],
            'previous_net_minor' => (string) $previous['net_minor'],
            'gross_diff_minor' => (string) BigInteger::of($line['gross_minor'])->minus($previous['gross_minor']),
            'net_diff_minor' => (string) BigInteger::of($line['net_minor'])->minus($previous['net_minor']),
            'gross_pct' => $pct((string) $line['gross_minor'], $previous['gross_minor']),
            'net_pct' => $pct((string) $line['net_minor'], $previous['net_minor']),
        ];
    }

    /**
     * Totals per pay currency and in the base currency, the previous run's
     * for the variance, and the employees no longer paid.
     *
     * @return array<string, mixed>
     */
    private function totals(Run $run, array $lines, array $previous, Collection $employees): array
    {
        $by = [];
        $base = ['gross_minor' => BigInteger::zero(), 'net_minor' => BigInteger::zero(), 'employer_minor' => BigInteger::zero()];
        $fields = ['gross_minor', 'statutory_minor', 'deductions_minor', 'loans_minor', 'net_minor', 'employer_minor'];

        foreach ($lines as $line) {
            $currency = $line['pay_currency'];
            $by[$currency] ??= [...array_fill_keys($fields, BigInteger::zero()), 'employees' => 0];
            $by[$currency]['employees']++;

            foreach ($fields as $field) {
                $by[$currency][$field] = $by[$currency][$field]->plus((string) $line[$field]);
            }

            $base['gross_minor'] = $base['gross_minor']->plus((string) $line['gross_base_minor']);
            $base['net_minor'] = $base['net_minor']->plus((string) $line['net_base_minor']);
            $base['employer_minor'] = $base['employer_minor']->plus((string) $line['employer_base_minor']);
        }

        $previousBy = [];

        foreach ($previous as $row) {
            $previousBy[$row['currency']] ??= ['gross_minor' => BigInteger::zero(), 'net_minor' => BigInteger::zero(), 'employees' => 0];
            $previousBy[$row['currency']]['gross_minor'] = $previousBy[$row['currency']]['gross_minor']->plus($row['gross_minor']);
            $previousBy[$row['currency']]['net_minor'] = $previousBy[$row['currency']]['net_minor']->plus($row['net_minor']);
            $previousBy[$row['currency']]['employees']++;
        }

        $strings = fn (array $rows) => array_map(fn (array $r) => array_map(fn ($v) => $v instanceof BigInteger ? (string) $v : $v, $r), $rows);
        $paid = array_column($lines, 'payroll_employee_id');

        return [
            'currencies' => $strings($by),
            'base' => ['currency' => $run->company?->base_currency, ...array_map('strval', $base)],
            'employees' => count($lines),
            'errors' => count(array_filter($lines, fn (array $l) => $l['errors'] !== [])),
            'previous' => [
                'run_id' => array_values($previous)[0]['run_id'] ?? null,
                'currencies' => $strings($previousBy),
                'gone' => array_values(array_map(fn (array $r) => ['number' => $r['number'], 'name' => $r['name']], array_diff_key($previous, array_flip($paid)))),
            ],
        ];
    }

    /** The rate between two currencies on the run's date (CUR-04), recorded once per pair; null and a line error when there is none. */
    private function snapshot(RunContext $context, string $from, string $to, array &$fx, array &$errors): ?FxSnapshot
    {
        $key = "{$from}/{$to}";

        if (isset($fx[$key])) {
            return new FxSnapshot($fx[$key]['rate'], $fx[$key]['base'], $fx[$key]['quote'], $fx[$key]['kind'] ?? null, isset($fx[$key]['effective_at']) ? CarbonImmutable::parse($fx[$key]['effective_at']) : null);
        }

        try {
            $snapshot = FxSnapshot::fromRate($this->rates->stored($context->company, $from, $to, $context->at));
        } catch (RateUnavailable) {
            $errors[] = 'rate_missing';

            return null;
        }

        $fx[$key] = $snapshot->jsonSerialize();

        return $snapshot;
    }

    /** $minor of $from in $to through $snapshot, rounded half up to $to's minor unit. */
    private function convert(string $minor, string $from, string $to, FxSnapshot $snapshot): string
    {
        return $snapshot->convert(Money::ofMinor($minor, $from))->minor();
    }

    /** $minor of $from in $to through $snapshot, exact (rounded later). */
    private function exactConvert(BigDecimal $minor, string $from, string $to, FxSnapshot $snapshot): BigDecimal
    {
        $scaled = $minor->withPointMovedRight($this->decimals->for($to) - $this->decimals->for($from));

        return $from === $snapshot->base
            ? $scaled->multipliedBy($snapshot->rate)
            : $scaled->dividedBy($snapshot->rate, 20, RoundingMode::HalfUp);
    }

    /** CUR-04 (decision 3): rates are taken at the end of the pay date in the company's time zone. */
    private function at(Run $run, Company $company): CarbonImmutable
    {
        return CarbonImmutable::parse($run->pay_date->toDateString(), $company->timezone ?: 'UTC')->endOfDay()->utc();
    }

    /** An exact amount in whole minor units, half up (PAY-09 pro-rating and premiums). */
    private static function minor(BigDecimal $exact): string
    {
        return (string) $exact->toScale(0, RoundingMode::HalfUp);
    }
}
