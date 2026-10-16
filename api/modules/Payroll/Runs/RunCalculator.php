<?php

namespace Modules\Payroll\Runs;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\HR\Contracts\AttendanceRecords;
use Modules\Payroll\Models\Attendance;
use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Models\Item;
use Modules\Payroll\Models\LeaveDay;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\LoanEntry;
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
        private readonly AttendanceRecords $attendanceRecords,
    ) {}

    /** @var array<string, ?FxSnapshot> rates read in this calculation, by "from/to" (one query per pair, not per line) */
    private array $rateCache = [];

    public function calculate(Run $run): void
    {
        $this->rateCache = [];
        $company = Company::query()->findOrFail($run->company_id);
        $group = PayGroup::query()->findOrFail($run->pay_group_id);
        $pack = $this->packs->of($company->country);

        if ($pack === null) {
            throw Errors::make(422, 'payroll_pack_missing', ['country' => (string) $company->country]);
        }

        // PAY-05: the copy was refreshed from HR first, in its own short transaction (Runs::calculate, decision 30).
        $context = new RunContext($run, $company, $group, $pack, Settings::query()->where('company_id', $company->id)->first(), $this->at($run, $company));
        $employees = $this->employees($run, $group);
        // Decision 39: what every employee needs is read once for the run, not per employee.
        $data = $this->load($run, $group, $employees);
        $prepared = [];

        foreach ($employees as $employee) {
            $prepared[$employee->id] = $run->kind === Run::CORRECTION && $run->correction_mode === Run::REVERSE
                ? null
                : $this->prepare($context, $employee, $data);

            // PAY-18: a recalculation keeps the loans as posted (their instalments are not recomputed).
            if ($prepared[$employee->id] !== null && $run->kind === Run::CORRECTION) {
                $prepared[$employee->id]['loans'] = $this->posted($data, $employee)['loans'];
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
            $line = $prepared[$employee->id] === null ? $this->zero($context, $employee, $data) : $this->finish($context, $employee, $prepared[$employee->id], $components, $headcount);

            if ($run->kind === Run::CORRECTION) {
                $line = $this->correct($line, $this->posted($data, $employee), $data['posted_lines'][$employee->id] ?? collect());
            }

            $lines[] = $line;
        }

        $previous = $this->previousLines($run);
        RunLine::query()->where('run_id', $run->id)->delete();

        foreach ($lines as &$line) {
            $line['variance'] = $this->variance($line, $previous[$line['payroll_employee_id']] ?? null);
        }

        unset($line);
        $this->insertLines($lines);

        // Review fix 5: what the run was calculated from (the pack's source and hash); decisions 29, 33, 40: what the reviewer should know.
        $run->fill(['missing' => null, 'totals' => $this->totals($run, $lines, $previous, $employees), 'inputs' => [
            'pack' => $this->packs->source($company->country),
            'review' => $this->review($run, $group, $previous),
        ]])->save();
    }

    /**
     * Decision 33: what the run's lines were calculated from, as Payroll's
     * copy stands now (one fingerprint per employee: hire and termination
     * dates that touch the period, its unpaid leave days and the attendance
     * periods it takes). Runs::assertCurrent compares them with the lines'.
     *
     * @return array<string, string> by payroll employee id
     */
    public function fingerprints(Run $run): array
    {
        if ($run->kind === Run::CORRECTION && $run->correction_mode === Run::REVERSE) {
            return [];
        }

        $group = PayGroup::query()->findOrFail($run->pay_group_id);
        $employees = $this->employees($run, $group);
        $data = $this->load($run, $group, $employees, only: ['attendance', 'leave']);
        $prints = [];

        foreach ($employees as $employee) {
            $prints[$employee->id] = self::fingerprint($run, $employee, $data['leave'][$employee->employee_id] ?? collect(), ($data['attendance'][$employee->employee_id] ?? collect())->pluck('id')->all());
        }

        ksort($prints);

        return $prints;
    }

    /** @param Collection<int, LeaveDay> $leave */
    private static function fingerprint(Run $run, PayrollEmployee $employee, Collection $leave, array $attendanceIds): string
    {
        sort($attendanceIds);
        $days = $leave->map(fn (LeaveDay $d) => $d->date->toDateString().'='.$d->fraction)->sort()->values()->all();

        return hash('sha256', json_encode([
            'hire' => $employee->hire_date->gte($run->period_start) ? $employee->hire_date->toDateString() : null,
            'termination' => $employee->termination_date !== null && $employee->termination_date->lte($run->period_end) ? $employee->termination_date->toDateString() : null,
            'leave' => $days,
            'attendance' => $attendanceIds,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return Collection<string, PayrollEmployee> by id */
    private function employees(Run $run, PayGroup $group): Collection
    {
        $query = PayrollEmployee::query()->where('pay_group_id', $group->id)->where('company_id', $run->company_id)
            ->whereDate('hire_date', '<=', $run->period_end)
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $run->period_start));

        if ($run->kind === Run::CORRECTION) {
            // PAY-18: the chosen employees paid by the period's posted runs, held or not.
            $paid = RunLine::query()->whereIn('run_id', $this->postedRunIds($run))->distinct()->pluck('payroll_employee_id')->all();
            $query = PayrollEmployee::query()->whereIn('id', array_values(array_intersect($paid, $run->correction_employee_ids ?? $paid)));
        } else {
            $query->where('on_hold', false);
        }

        return $query->orderBy('number')->get()->keyBy('id');
    }

    /**
     * Decision 39: the run's inputs for all its employees, a query per kind
     * (contracts, attendance, leave, items, loans and their balances, other
     * runs, posted lines), grouped by employee.
     *
     * @param  Collection<string, PayrollEmployee>  $employees
     * @param  list<string>|null  $only  the kinds to read (all when null)
     * @return array<string, mixed>
     */
    private function load(Run $run, PayGroup $group, Collection $employees, ?array $only = null): array
    {
        $want = fn (string $kind) => $only === null || in_array($kind, $only, true);
        $hrIds = $employees->pluck('employee_id')->all();
        $ids = $employees->keys()->all();
        $posted = $run->kind === Run::CORRECTION ? $this->postedRunIds($run) : [];
        $data = ['posted_run_ids' => $posted, 'contracts' => [], 'attendance' => [], 'leave' => [], 'items' => [], 'loans' => [], 'balances' => [], 'other_runs' => [], 'posted_lines' => []];

        if ($employees->isEmpty()) {
            return $data;
        }

        if ($want('contracts')) {
            $data['contracts'] = PayrollContract::query()->whereIn('employee_id', $hrIds)
                ->whereDate('start_date', '<=', $run->period_end)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $run->period_start))
                ->orderBy('start_date')->get()->groupBy('employee_id')->all();
        }

        if ($want('attendance')) {
            $data['attendance'] = $this->attendanceQuery($run, $group, $posted)->whereIn('employee_id', $hrIds)
                ->orderBy('end_date')->orderBy('period_id')->get()->groupBy('employee_id')->all();
        }

        if ($want('leave')) {
            $data['leave'] = LeaveDay::query()->whereIn('employee_id', $hrIds)->whereNull('cancelled_at')
                ->whereDate('date', '>=', $run->period_start)->whereDate('date', '<=', $run->period_end)
                ->orderBy('date')->get()->groupBy('employee_id')->all();
        }

        if ($want('items')) {
            $data['items'] = $this->itemsQuery($run, $posted)->whereIn('payroll_employee_id', $ids)->get()->groupBy('payroll_employee_id')->all();
        }

        if ($want('loans') && $run->kind === Run::REGULAR) {
            $loans = Loan::query()->whereIn('payroll_employee_id', $ids)->where('status', Loan::ACTIVE)->whereDate('starts_on', '<=', $run->period_end)
                ->orderBy('starts_on')->orderBy('id')->get();
            $data['loans'] = $loans->groupBy('payroll_employee_id')->all();
            $data['balances'] = $loans->isEmpty() ? [] : LoanEntry::query()->whereIn('loan_id', $loans->modelKeys())
                ->groupBy('loan_id')->selectRaw('loan_id, sum(amount_minor) as balance')->pluck('balance', 'loan_id')->map(fn ($b) => (int) $b)->all();
        }

        if ($want('other_runs') && $run->kind === Run::REGULAR) {
            // Review fix 3: never paid twice for the same days by two pay groups' regular runs.
            $data['other_runs'] = array_flip(RunLine::query()->whereIn('payroll_employee_id', $ids)
                ->whereIn('run_id', Run::query()->where('kind', Run::REGULAR)->where('status', '<>', Run::CANCELLED)->whereKeyNot($run->id)
                    ->whereDate('period_start', '<=', $run->period_end)->whereDate('period_end', '>=', $run->period_start)->select('id'))
                ->distinct()->pluck('payroll_employee_id')->all());
        }

        if ($want('posted_lines') && $posted !== []) {
            $data['posted_lines'] = RunLine::query()->whereIn('run_id', $posted)->whereIn('payroll_employee_id', $ids)
                ->orderBy('created_at')->orderBy('id')->get()->groupBy('payroll_employee_id')->all();
        }

        return $data;
    }

    /**
     * Decisions 29, 33 and 40: what the run's reviewer should know beyond
     * its lines: attendance periods overlapping it that are still open in
     * HR (their time is paid by a later run, as arrears), HR changes since
     * the last posted run that it did not pay from (late changes: leave or
     * a termination in its period; a correction may be needed), and
     * employees employed in the period without a pay group (paid by no run).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function review(Run $run, PayGroup $group, array $previous): array
    {
        if ($run->kind !== Run::REGULAR) {
            return [];
        }

        $open = array_map(fn (array $p) => ['start_date' => $p['start_date'], 'end_date' => $p['end_date']],
            $this->attendanceRecords->openOverlapping($run->company_id, $run->period_start->toDateString(), $run->period_end->toDateString()));

        $noGroup = PayrollEmployee::query()->where('company_id', $run->company_id)->whereNull('pay_group_id')->where('on_hold', false)
            ->whereDate('hire_date', '<=', $run->period_end)
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $run->period_start))
            ->orderBy('number')->get(['number', 'name', 'hire_date'])
            ->map(fn (PayrollEmployee $e) => ['number' => $e->number, 'name' => $e->name, 'hire_date' => $e->hire_date->toDateString()])->all();

        return array_filter([
            'attendance_open' => $open,
            'late_changes' => $this->lateChanges($run, $previous),
            'no_pay_group' => $noGroup,
        ]);
    }

    /**
     * Decision 33: the employees of the last posted regular run of the pay
     * group whose leave, hire or termination in that run's period changed
     * after it was posted (their latest posted line there, a correction's
     * included, was calculated from other inputs).
     *
     * @return list<array{number: string, name: string, run_number: string}>
     */
    private function lateChanges(Run $run, array $previous): array
    {
        $runId = array_values($previous)[0]['run_id'] ?? null;
        $last = $runId === null ? null : Run::query()->find($runId);

        if ($last === null) {
            return [];
        }

        $runs = Run::query()->where('pay_group_id', $last->pay_group_id)->whereDate('period_start', $last->period_start)->where('status', Run::POSTED)
            ->orderBy('posted_at')->get(['id', 'number', 'kind', 'correction_mode', 'posted_at'])->keyBy('id');
        $latest = [];

        foreach (RunLine::query()->whereIn('run_id', $runs->keys())->get(['run_id', 'payroll_employee_id', 'number', 'name', 'inputs']) as $line) {
            $order = $runs->keys()->search($line->run_id);

            if (! isset($latest[$line->payroll_employee_id]) || $order >= $latest[$line->payroll_employee_id][0]) {
                $latest[$line->payroll_employee_id] = [$order, $line, $runs[$line->run_id]];
            }
        }

        $latest = array_filter($latest, fn (array $l) => isset($l[1]->inputs['fingerprint']) && $l[2]->correction_mode !== Run::REVERSE);

        if ($latest === []) {
            return [];
        }

        $employees = PayrollEmployee::query()->whereIn('id', array_keys($latest))->get()->keyBy('id');
        $leave = LeaveDay::query()->whereIn('employee_id', $employees->pluck('employee_id'))->whereNull('cancelled_at')
            ->whereDate('date', '>=', $last->period_start)->whereDate('date', '<=', $last->period_end)->get()->groupBy('employee_id');
        $changed = [];

        foreach ($latest as $id => [, $line]) {
            $employee = $employees[$id] ?? null;

            if ($employee !== null && self::fingerprint($last, $employee, $leave[$employee->employee_id] ?? collect(), $line->inputs['attendance_ids'] ?? []) !== $line->inputs['fingerprint']) {
                $changed[] = ['number' => $line->number, 'name' => $line->name, 'run_number' => $last->number];
            }
        }

        usort($changed, fn (array $a, array $b) => strcmp($a['number'], $b['number']));

        return $changed;
    }

    /**
     * Step 1: one employee's gross, deductions and loans in the pay
     * currency, and their bases for the engine.
     *
     * @return array<string, mixed>
     */
    private function prepare(RunContext $context, PayrollEmployee $employee, array $data): array
    {
        $run = $context->run;
        $errors = [];
        $warnings = [];
        $fx = [];
        $earnings = [];
        $deductions = [];
        $inputs = ['item_ids' => [], 'attendance_ids' => [], 'leave_days' => '0', 'days' => []];

        $contracts = collect($data['contracts'][$employee->employee_id] ?? []);
        $last = $contracts->last();
        $payCurrency = $employee->pay_currency ?? $last?->currency ?? $context->company->base_currency;

        if ($contracts->isEmpty()) {
            $warnings[] = 'no_contract';
        }

        // Review fix 3: never paid twice for the same days by two pay groups' regular runs.
        if (isset($data['other_runs'][$employee->id])) {
            $errors[] = 'paid_in_other_run';
        }

        // Converts a contract amount into the pay currency at the run's rate (CUR-04).
        $toPay = function (BigDecimal $minor, string $from) use ($context, $payCurrency, &$fx, &$errors): BigDecimal {
            if ($from === $payCurrency) {
                return $minor;
            }

            $snapshot = $this->snapshot($context, $from, $payCurrency, $fx, $errors);

            return $snapshot === null ? BigDecimal::zero() : $this->exactConvert($minor, $from, $payCurrency, $snapshot);
        };

        // Decision 29: closed HR periods ending in the run's period or before it (arrears: closed after
        // their own run), not paid yet (with the ones this period's posted runs paid, for a correction).
        $all = collect($data['attendance'][$employee->employee_id] ?? []);
        [$arrears, $attendance] = $all->partition(fn (Attendance $a) => $a->end_date->lt($run->period_start));
        $inputs['attendance_ids'] = $all->pluck('id')->all();
        $inputs['arrears'] = $arrears->map(fn (Attendance $a) => ['period_id' => $a->period_id, 'start_date' => $a->start_date->toDateString(), 'end_date' => $a->end_date->toDateString()])->values()->all();
        $minutes = fn (string $field, ?Collection $rows = null) => (int) ($rows ?? $attendance)->sum($field);
        $periodDays = $run->period_start->diffInDays($run->period_end) + 1;
        $share = BigDecimal::of(12)->dividedBy($context->group->periodsPerYear(), 12, RoundingMode::HalfUp);
        $basic = BigDecimal::zero();
        $hourly = null;
        $daily = null;

        foreach ($contracts as $contract) {
            $pay = $toPay(BigDecimal::of($contract->pay_minor), $contract->currency);
            // Decision 37: a contract that ended before the current hire date belongs to an earlier
            // stretch of employment (a rehire in the period): it is paid for its own days.
            $earlierStretch = $contract->end_date !== null && $contract->end_date->lt($employee->hire_date);
            $from = CarbonImmutable::parse(max($contract->start_date->toDateString(), $run->period_start->toDateString(), $earlierStretch ? '0001-01-01' : $employee->hire_date->toDateString()));
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

        // Daily and hourly pay of a period's present days and regular hours.
        $timePay = function (Collection $rows) use ($last, $toPay, $minutes): BigDecimal {
            $rate = $toPay(BigDecimal::of($last->pay_minor), $last->currency);

            return $last->pay_basis === 'daily'
                ? $rate->multipliedBy($minutes('days_present', $rows))
                : $rate->multipliedBy($minutes('regular_minutes', $rows))->dividedBy(60, 12, RoundingMode::HalfUp);
        };
        $arrearsBasic = null;

        if ($last !== null && $last->pay_basis !== 'monthly') {
            if ($attendance->isEmpty()) {
                $warnings[] = 'no_attendance';
            } else {
                $basic = $basic->plus($timePay($attendance));
            }

            if ($arrears->isNotEmpty()) {
                $arrearsBasic = $timePay($arrears);
            }
        }

        $basicMinor = self::minor($basic);
        $earnings[] = ['code' => 'basic', 'name' => __('payroll.lines.basic'), 'source' => 'basic', 'amount_minor' => $basicMinor, 'taxable' => true, 'contributory' => true];

        if ($arrearsBasic !== null && ! $arrearsBasic->isZero()) {
            $earnings[] = ['code' => 'basic_arrears', 'name' => __('payroll.lines.arrears', ['line' => __('payroll.lines.basic')]), 'source' => 'attendance', 'amount_minor' => self::minor($arrearsBasic), 'taxable' => true, 'contributory' => true, 'arrears' => true];
        }

        // PAY-11: unpaid leave of monthly pay at the daily rate.
        $leave = collect($data['leave'][$employee->employee_id] ?? []);
        $inputs['fingerprint'] = self::fingerprint($run, $employee, $leave, $inputs['attendance_ids']);

        if ($last?->pay_basis === 'monthly') {
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

        foreach ([[$attendance, ''], [$arrears, '_arrears']] as [$rows, $suffix]) {
            foreach ($premiums as $code => [$field, $setting, $onTop]) {
                $count = $minutes($field, $rows);

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
                $name = __("payroll.lines.{$code}");
                $earnings[] = [
                    'code' => $code.$suffix, 'name' => $suffix === '' ? $name : __('payroll.lines.arrears', ['line' => $name]), 'source' => 'attendance', 'amount_minor' => self::minor($amount),
                    'taxable' => (bool) ($context->settings->premiums_taxable ?? true), 'contributory' => (bool) ($context->settings->premiums_contributory ?? true),
                    'quantity' => (string) BigDecimal::of($count)->dividedBy(60, 2, RoundingMode::HalfUp), 'multiplier' => rtrim(rtrim((string) $multiplier, '0'), '.'),
                    ...($suffix === '' ? [] : ['arrears' => true]),
                ];
            }
        }

        // PAY-06: the employee's recurring items and this period's one-offs.
        foreach ($data['items'][$employee->id] ?? [] as $entry) {
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
            foreach ($data['loans'][$employee->id] ?? [] as $loan) {
                $balance = $data['balances'][$loan->id] ?? 0;

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
    private function zero(RunContext $context, PayrollEmployee $employee, array $data): array
    {
        $posted = collect($data['posted_lines'][$employee->id] ?? [])->first();
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
     * lines, payslips and journals carry the signed differences. Its
     * department, branch and location are the posted line's (decision 34:
     * the journals correct the department that was charged, not the
     * employee's current one); an employee now paid in another currency
     * than the posted lines is a line error (`currency_mismatch`, decision
     * 35: differences are never taken across currencies).
     *
     * @param  Collection<int, RunLine>  $postedLines
     */
    private function correct(array $line, array $posted, Collection $postedLines): array
    {
        $last = $postedLines->last();

        if ($last !== null) {
            foreach (['branch_id', 'location_id', 'department_id', 'number', 'name'] as $field) {
                $line[$field] = $last->{$field};
            }

            if ($postedLines->contains(fn (RunLine $l) => $l->pay_currency !== $line['pay_currency'])) {
                $line['errors'][] = 'currency_mismatch';
            }
        }

        return $this->difference($line, $posted);
    }

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
    private function posted(array $data, PayrollEmployee $employee): array
    {
        $lines = collect($data['posted_lines'][$employee->id] ?? []);
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

    /**
     * Decision 29: the attendance a run pays. A regular run takes every
     * closed period ending on or before its period's end not paid yet,
     * back to the first period its pay group paid (earlier periods, closed
     * after their run, are arrears; history from before Payroll is not); a
     * correction takes what the period's posted runs paid and what is still
     * unpaid in its period.
     *
     * @param  list<string>  $posted  the period's posted run ids
     */
    private function attendanceQuery(Run $run, PayGroup $group, array $posted): Builder
    {
        $query = Attendance::query()->whereDate('end_date', '<=', $run->period_end);

        if ($run->kind === Run::CORRECTION) {
            return $query->where(fn ($q) => $q->whereIn('paid_run_id', $posted)
                ->orWhere(fn ($u) => $u->whereNull('paid_run_id')->whereDate('end_date', '>=', $run->period_start)));
        }

        $first = Run::query()->where('pay_group_id', $group->id)->where('kind', Run::REGULAR)->where('status', '<>', Run::CANCELLED)->min('period_start');
        $from = $first === null ? $run->period_start : CarbonImmutable::parse(min(CarbonImmutable::parse($first)->toDateString(), $run->period_start->toDateString()));

        return $query->whereNull('paid_run_id')->whereDate('end_date', '>=', $from);
    }

    /** @param list<string> $posted the period's posted run ids */
    private function itemsQuery(Run $run, array $posted): Builder
    {
        return EmployeeItem::query()->with('item')->whereNull('archived_at')
            ->where(function ($q) use ($run, $posted) {
                $q->where(fn ($r) => $r->where('recurrence', EmployeeItem::RECURRING)->whereDate('from_date', '<=', $run->period_end)
                    ->where(fn ($t) => $t->whereNull('to_date')->orWhereDate('to_date', '>=', $run->period_start)))
                    ->orWhere(fn ($o) => $o->where('recurrence', EmployeeItem::ONCE)->whereDate('period_date', '>=', $run->period_start)->whereDate('period_date', '<=', $run->period_end)
                        ->where(fn ($c) => $c->whereNull('consumed_run_id')->orWhereIn('consumed_run_id', $posted)));
            })
            ->orderBy('created_at')->orderBy('id');
    }

    /** @param list<array<string, mixed>> $lines the run's lines, written in a few statements */
    private function insertLines(array $lines): void
    {
        $tenantId = app(TenantContext::class)->require();
        $now = CarbonImmutable::now();
        $json = ['fx', 'earnings', 'deductions', 'statutory', 'loans', 'inputs', 'variance', 'errors', 'warnings'];
        $rows = array_map(function (array $line) use ($tenantId, $now, $json) {
            foreach ($json as $field) {
                $line[$field] = json_encode($line[$field] ?? null, JSON_THROW_ON_ERROR);
            }

            return [...$line, 'tenant_id' => $tenantId, 'created_at' => $now, 'updated_at' => $now];
        }, $lines);

        foreach (array_chunk($rows, 200) as $chunk) {
            RunLine::query()->insert($chunk);
        }
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

        // Decision 36: department totals and a variance summary, so an approver without
        // `payroll.pay.view` judges the run without per-employee lines.
        $departments = [];

        foreach ($lines as $line) {
            $key = ($line['department_id'] ?? '').'|'.$line['pay_currency'];
            $departments[$key] ??= ['department_id' => $line['department_id'] ?? null, 'currency' => $line['pay_currency'], 'employees' => 0, 'gross_minor' => BigInteger::zero(), 'net_minor' => BigInteger::zero(), 'employer_minor' => BigInteger::zero()];
            $departments[$key]['employees']++;

            foreach (['gross_minor', 'net_minor', 'employer_minor'] as $field) {
                $departments[$key][$field] = $departments[$key][$field]->plus((string) $line[$field]);
            }
        }

        $names = Department::query()->whereIn('id', array_filter(array_column($departments, 'department_id')))->pluck('name', 'id');
        $departments = array_map(fn (array $d) => [...$d, 'name' => $d['department_id'] === null ? null : ($names[$d['department_id']] ?? null)], $strings(array_values($departments)));
        usort($departments, fn (array $a, array $b) => [$a['name'] === null, $a['name'], $a['currency']] <=> [$b['name'] === null, $b['name'], $b['currency']]);

        $statuses = array_map(fn (array $l) => $l['variance']['status'] ?? null, $lines);
        $changed = array_filter($lines, fn (array $l) => ($l['variance']['status'] ?? null) === 'changed' && $l['variance']['net_diff_minor'] !== '0');

        return [
            'currencies' => $strings($by),
            'base' => ['currency' => $run->company?->base_currency, ...array_map('strval', $base)],
            'employees' => count($lines),
            'errors' => count(array_filter($lines, fn (array $l) => $l['errors'] !== [])),
            'departments' => $departments,
            'variance' => [
                'new' => count(array_keys($statuses, 'new', true)),
                'gone' => count(array_diff_key($previous, array_flip($paid))),
                'changed' => count($changed),
                'changed_over_10_pct' => count(array_filter($changed, fn (array $l) => $l['variance']['net_pct'] === null || abs((float) $l['variance']['net_pct']) > 10)),
            ],
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

        if (! array_key_exists($key, $this->rateCache)) {
            try {
                $this->rateCache[$key] = FxSnapshot::fromRate($this->rates->stored($context->company, $from, $to, $context->at));
            } catch (RateUnavailable) {
                $this->rateCache[$key] = null;
            }
        }

        $snapshot = $this->rateCache[$key];

        if ($snapshot === null) {
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
