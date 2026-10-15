<?php

namespace Modules\Payroll\Runs;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Payroll\Employees\EmployeeCopy;
use Modules\Payroll\Events\PayrollApproved;
use Modules\Payroll\Models\Attendance;
use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\LoanEntry;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Support\Errors;
use Modules\Payroll\Support\Numbers;

/**
 * PAY-14 to PAY-18: a run's life. Draft → calculated (again as often as
 * needed) → pending (its `payroll.run` flow; the preparer and the
 * submitter never approve it, APR-07) → approved (PayrollApproved) →
 * posted (locked for good: loans repaid, one-offs and attendance marked
 * paid, payslips issued, PayrollPosted). Rejected or withdrawn flows bring
 * it back to calculated. A posted run is never edited: a correction run
 * carries the differences.
 */
class Runs
{
    public function __construct(
        private readonly RunCalculator $calculator,
        private readonly WorkflowEngine $engine,
        private readonly Numbers $numbers,
        private readonly Auditor $auditor,
        private readonly EmployeeCopy $copy,
    ) {}

    /** A regular run of a pay group for the period containing `period_date` (default: today in the company's time zone). */
    public function create(array $data, User $by): Run
    {
        $group = PayGroup::query()->findOrFail($data['pay_group_id']);

        if ($group->isArchived()) {
            throw Errors::make(422, 'pay_group_archived', field: 'pay_group_id');
        }

        $company = Company::query()->findOrFail($group->company_id);
        $date = CarbonImmutable::parse($data['period_date'] ?? CarbonImmutable::now($company->timezone ?: 'UTC')->toDateString());
        [$start, $end] = $group->periodOf($date);
        $payDate = isset($data['pay_date']) ? CarbonImmutable::parse($data['pay_date']) : $group->payDateFor($start, $end);

        return Errors::transaction(function () use ($group, $start, $end, $payDate, $data, $by) {
            if (Run::query()->where('pay_group_id', $group->id)->where('kind', Run::REGULAR)->whereDate('period_start', $start)->where('status', '<>', Run::CANCELLED)->exists()) {
                throw Errors::make(422, 'run_exists', field: 'period_date');
            }

            try {
                return DB::connection(TenantContext::CONNECTION)->transaction(fn () => Run::query()->create([
                    'number' => $this->numbers->next($group->company_id),
                    'company_id' => $group->company_id,
                    'pay_group_id' => $group->id,
                    'kind' => Run::REGULAR,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'pay_date' => $payDate->toDateString(),
                    'status' => Run::DRAFT,
                    'note' => $data['note'] ?? null,
                    'created_by' => $by->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw Errors::make(422, 'run_exists', field: 'period_date');
            }
        });
    }

    /** PAY-18: a correction of posted run $run for the chosen employees (all of the period's when none). */
    public function correction(Run $run, array $data, User $by): Run
    {
        if (! $run->isPosted()) {
            throw Errors::make(422, 'run_not_posted');
        }

        $original = $run->kind === Run::CORRECTION ? Run::query()->findOrFail($run->corrects_run_id) : $run;

        try {
            return Errors::transaction(fn () => $this->createCorrection($run, $original, $data, $by));
        } catch (UniqueConstraintViolationException) {
            // Review fix 2: one open correction per pay group and period.
            throw Errors::make(422, 'correction_open');
        }
    }

    private function createCorrection(Run $run, Run $original, array $data, User $by): Run
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(fn () => Run::query()->create([
            'number' => $this->numbers->next($run->company_id),
            'company_id' => $run->company_id,
            'pay_group_id' => $run->pay_group_id,
            'kind' => Run::CORRECTION,
            'corrects_run_id' => $original->id,
            'correction_mode' => $data['mode'],
            'correction_employee_ids' => $data['employee_ids'] ?? null,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'pay_date' => $data['pay_date'] ?? CarbonImmutable::now()->toDateString(),
            'status' => Run::DRAFT,
            'note' => $data['note'] ?? null,
            'created_by' => $by->id,
        ]));
    }

    public function calculate(Run $run, User $by): Run
    {
        // PAY-08: a refused calculation keeps what is missing on the run (so the screen can show it).
        $missing = null;
        $this->refresh($run, [Run::DRAFT, Run::CALCULATED]);

        try {
            Errors::transaction(function () use ($run, $by) {
                // Review fix 7: the status is read under the lock, inside the transaction.
                $run = $this->locked($run, [Run::DRAFT, Run::CALCULATED]);
                $this->calculator->calculate($run);
                $run->fill(['status' => Run::CALCULATED, 'calculated_by' => $by->id, 'calculated_at' => CarbonImmutable::now(), 'rejected_at' => null])->save();
                $this->auditor->record('payroll.run.calculate', $run, null, ['employees' => $run->lines()->count()]);
            });
        } catch (ApiException $e) {
            if ($e->errorCode === 'statutory_rates_missing') {
                $missing = $e->extra['missing'] ?? [];
                Run::query()->whereKey($run->id)->first()?->fill(['missing' => $missing])->save();
            }

            throw $e;
        }

        return $run->refresh();
    }

    /**
     * Decision 30: Payroll's copy of HR refreshed (PAY-05), with the audit
     * rows of HR's contract read, in a short transaction of its own, so the
     * audit chain's lock is not held through a whole calculation; only for
     * a run in one of $statuses (422 `run_status` otherwise).
     */
    private function refresh(Run $run, array $statuses): void
    {
        Errors::transaction(function () use ($run, $statuses) {
            $run = $this->locked($run, $statuses);
            $this->copy->syncCompany($run->company_id, $run->id);
        });
    }

    /** PAY-16: the run's flow starts; its requester is the preparer (APR-07). */
    public function submit(Run $run, User $by): Run
    {
        $this->refresh($run, [Run::CALCULATED]);

        return Errors::transaction(function () use ($run, $by) {
            $run = $this->locked($run, [Run::CALCULATED]);
            $lines = RunLine::query()->where('run_id', $run->id)->get(['id', 'errors']);

            if ($lines->isEmpty()) {
                throw Errors::make(422, 'run_empty');
            }

            if ($lines->contains(fn (RunLine $l) => $l->errors !== [])) {
                throw Errors::make(422, 'run_has_errors', ['count' => $lines->filter(fn (RunLine $l) => $l->errors !== [])->count()]);
            }

            // Review fixes 1 and 2: what the run was calculated from still holds.
            $this->assertCurrent($run);

            $run->fill(['status' => Run::PENDING, 'submitted_by' => $by->id, 'submitted_at' => CarbonImmutable::now()])->save();
            $this->engine->start(RunType::KEY, $run->id, $by);

            return $run->refresh();
        });
    }

    public function cancel(Run $run, User $by, ?string $reason): Run
    {
        return Errors::transaction(function () use ($run, $by, $reason) {
            // An approved run whose post is refused (a loan changed meanwhile) can be cancelled and prepared again.
            $run = $this->locked($run, [Run::DRAFT, Run::CALCULATED, Run::PENDING, Run::APPROVED]);

            if ($run->status === Run::PENDING && ($flow = $this->engine->current(RunType::KEY, $run->id)) !== null && $flow->status === 'running') {
                $this->engine->cancel($flow, null, (string) $reason);
            }

            $run->fill(['status' => Run::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('payroll.run.cancel', $run, null, ['reason' => $reason]);

            return $run;
        });
    }

    /** WF-10: the flow ended. Approved: PayrollApproved. Rejected: back to calculated. */
    public function completed(string $runId, string $outcome, ?string $userId): void
    {
        Errors::transaction(function () use ($runId, $outcome) {
            $run = Run::query()->whereKey($runId)->lockForUpdate()->first();

            if ($run === null || $run->status !== Run::PENDING) {
                return;
            }

            if ($outcome !== 'approved') {
                $run->fill(['status' => Run::CALCULATED, 'rejected_at' => CarbonImmutable::now()])->save();

                return;
            }

            $run->fill(['status' => Run::APPROVED, 'approved_at' => CarbonImmutable::now()])->save();
            PayrollApproved::dispatch($run->tenant_id, $run->id, $run->number, $run->company_id, $run->kind, $run->period_start->toDateString(),
                $run->period_end->toDateString(), $run->pay_date->toDateString(), $run->totals['currencies'] ?? []);
        });
    }

    /** WF-11: the flow was cancelled (withdrawn); the run is calculated again. */
    public function flowCancelled(string $runId): void
    {
        Errors::transaction(function () use ($runId) {
            $run = Run::query()->whereKey($runId)->lockForUpdate()->first();

            if ($run !== null && $run->status === Run::PENDING) {
                $run->fill(['status' => Run::CALCULATED])->save();
            }
        });
    }

    /** PAY-17: lock and post an approved run. */
    public function post(Run $run, User $by): Run
    {
        $this->refresh($run, [Run::APPROVED]);
        $run = Errors::transaction(function () use ($run, $by) {
            $run = $this->locked($run, [Run::APPROVED]);
            // Review fixes 1 and 2: loans and the corrected runs are as they were when it was calculated.
            $this->assertCurrent($run);
            $lines = RunLine::query()->where('run_id', $run->id)->orderBy('number')->get();
            $now = CarbonImmutable::now();

            foreach ($lines as $line) {
                $this->repayLoans($run, $line, $by);

                if ($run->kind === Run::REGULAR) {
                    EmployeeItem::query()->whereIn('id', $line->inputs['item_ids'] ?? [])->where('recurrence', EmployeeItem::ONCE)->whereNull('consumed_run_id')
                        ->get()->each(fn (EmployeeItem $i) => $i->fill(['consumed_run_id' => $run->id])->save());
                    Attendance::query()->whereIn('id', $line->inputs['attendance_ids'] ?? [])->whereNull('paid_run_id')->update(['paid_run_id' => $run->id, 'updated_at' => $now]);
                }

                Payslip::query()->create([
                    'run_id' => $run->id,
                    'run_line_id' => $line->id,
                    'payroll_employee_id' => $line->payroll_employee_id,
                    'employee_id' => $line->employee_id,
                    'company_id' => $line->company_id,
                    'number' => $run->number.'/'.$line->number,
                    'issued_at' => $now,
                ]);
            }

            $run->fill(['status' => Run::POSTED, 'posted_by' => $by->id, 'posted_at' => $now])->save();
            // PAY-22: Accounting journals it (when both modules are active), after commit.
            event(PostingGroups::event($run));

            return $run;
        });

        return $run->refresh();
    }

    /**
     * Review fixes 1 and 2: 409 `loan_changed` when a loan the run deducts is
     * no longer active or its balance is below the deduction (settled,
     * cancelled or repaid by another run since), and 409
     * `correction_stale` when the posted runs a correction was calculated
     * against have changed. Recalculating the run clears both. Loans are
     * locked for the rest of the transaction.
     */
    private function assertCurrent(Run $run): void
    {
        $lines = RunLine::query()->where('run_id', $run->id)->get(['id', 'payroll_employee_id', 'loans', 'inputs']);

        foreach ($lines as $line) {
            foreach ($line->loans as $deduction) {
                $amount = (int) $deduction['amount_minor'];

                if ($amount <= 0) {
                    continue;
                }

                $loan = Loan::query()->whereKey($deduction['loan_id'])->lockForUpdate()->first();

                if ($loan === null || $loan->status !== Loan::ACTIVE || $loan->balance() < $amount) {
                    throw Errors::make(409, 'loan_changed');
                }
            }
        }

        // Decision 33: the leave, attendance, hire and termination inputs it was calculated from still hold.
        $then = $lines->mapWithKeys(fn (RunLine $l) => [$l->payroll_employee_id => $l->inputs['fingerprint'] ?? null])->all();
        $now = $this->calculator->fingerprints($run);

        if ($now !== [] || $run->correction_mode !== Run::REVERSE) {
            ksort($then);

            if ($now !== $then) {
                throw Errors::make(409, 'run_stale');
            }
        }

        if ($run->kind !== Run::CORRECTION) {
            return;
        }

        $posted = Run::query()->where('pay_group_id', $run->pay_group_id)->whereDate('period_start', $run->period_start)->where('status', Run::POSTED)->pluck('id')->all();

        foreach ($lines as $line) {
            $now = RunLine::query()->whereIn('run_id', $posted)->where('payroll_employee_id', $line->payroll_employee_id)->pluck('run_id')->unique()->sort()->values()->all();
            $then = collect($line->inputs['corrects'] ?? [])->unique()->sort()->values()->all();

            if ($now !== $then) {
                throw Errors::make(409, 'correction_stale');
            }
        }
    }

    /** PAY-13: the line's loan deductions as ledger entries; a loan paid off is settled, one given back is active again. */
    private function repayLoans(Run $run, RunLine $line, User $by): void
    {
        foreach ($line->loans as $deduction) {
            $amount = (int) $deduction['amount_minor'];

            if ($amount === 0) {
                continue;
            }

            $loan = Loan::query()->whereKey($deduction['loan_id'])->lockForUpdate()->firstOrFail();
            LoanEntry::query()->create([
                'loan_id' => $loan->id,
                'kind' => $amount > 0 ? LoanEntry::REPAYMENT : LoanEntry::REVERSAL,
                'amount_minor' => -$amount,
                'run_id' => $run->id,
                'entry_date' => $run->pay_date->toDateString(),
                'created_by' => $by->id,
            ]);

            $balance = $loan->balance();
            $status = $balance <= 0 ? Loan::SETTLED : ($loan->status === Loan::CANCELLED ? Loan::CANCELLED : Loan::ACTIVE);
            $loan->fill(['status' => $status, 'settle_next_run' => $amount > 0 ? false : $loan->settle_next_run])->save();
        }
    }

    /** $run locked for update, in one of $statuses (422 `run_status` otherwise). */
    private function locked(Run $run, array $statuses): Run
    {
        $fresh = Run::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

        if (! in_array($fresh->status, $statuses, true)) {
            throw Errors::make(422, 'run_status', ['status' => __('payroll.statuses.'.$fresh->status)]);
        }

        return $fresh;
    }
}
