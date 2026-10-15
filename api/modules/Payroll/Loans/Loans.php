<?php

namespace Modules\Payroll\Loans;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\LoanEntry;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Support\Errors;

/**
 * PAY-13: loans and salary advances. Interest-free (decision 9); the
 * disbursement is paid outside payroll and recorded here as the ledger's
 * first entry. Runs deduct instalments (Runs::post writes the
 * repayments); early settlement is paid outside payroll (a settlement
 * entry) or taken in full by the next run. Cancelled only before anything
 * was repaid.
 */
class Loans
{
    public function __construct(private readonly Auditor $auditor) {}

    public function create(PayrollEmployee $employee, array $data, User $by): Loan
    {
        $currency = $employee->effectivePayCurrency();

        if ($data['currency'] !== $currency) {
            throw Errors::make(422, 'currency_mismatch', field: 'currency');
        }

        if ((int) $data['instalment_minor'] > (int) $data['principal_minor']) {
            throw Errors::make(422, 'instalment_above_principal', field: 'instalment_minor');
        }

        return Errors::transaction(function () use ($employee, $data, $by) {
            $loan = Loan::query()->create([
                'payroll_employee_id' => $employee->id,
                'company_id' => $employee->company_id,
                'kind' => $data['kind'],
                'reference' => $data['reference'] ?? null,
                'principal_minor' => (int) $data['principal_minor'],
                'currency' => $data['currency'],
                'disbursed_on' => $data['disbursed_on'],
                'instalment_minor' => (int) $data['instalment_minor'],
                'starts_on' => $data['starts_on'] ?? $data['disbursed_on'],
                'status' => Loan::ACTIVE,
                'note' => $data['note'] ?? null,
                'created_by' => $by->id,
            ]);

            LoanEntry::query()->create([
                'loan_id' => $loan->id, 'kind' => LoanEntry::DISBURSEMENT, 'amount_minor' => $loan->principal_minor,
                'entry_date' => $loan->disbursed_on->toDateString(), 'created_by' => $by->id,
            ]);

            return $loan;
        });
    }

    /** Change the instalment or the first deduction date of an active loan. */
    public function update(Loan $loan, array $data): Loan
    {
        if ($loan->status !== Loan::ACTIVE) {
            throw Errors::make(422, 'loan_settled');
        }

        $loan->fill(array_intersect_key($data, array_flip(['instalment_minor', 'starts_on', 'reference', 'note'])))->save();

        return $loan;
    }

    /**
     * Early settlement: `external` (paid outside payroll: the balance is
     * written off the ledger as a settlement) or `next_run` (the next run
     * deducts the whole balance).
     */
    public function settle(Loan $loan, string $method, ?string $date, ?string $note, User $by): Loan
    {
        return Errors::transaction(function () use ($loan, $method, $date, $note, $by) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== Loan::ACTIVE) {
                throw Errors::make(422, 'loan_settled');
            }

            $this->assertNotInFlow($loan);

            if ($method === 'next_run') {
                $loan->fill(['settle_next_run' => true])->save();
                $this->auditor->record('payroll.loan.settle_next_run', $loan);

                return $loan;
            }

            $balance = $loan->balance();
            LoanEntry::query()->create([
                'loan_id' => $loan->id, 'kind' => LoanEntry::SETTLEMENT, 'amount_minor' => -$balance,
                'entry_date' => $date ?? CarbonImmutable::now()->toDateString(), 'note' => $note, 'created_by' => $by->id,
            ]);
            $loan->fill(['status' => Loan::SETTLED, 'settle_next_run' => false])->save();
            $this->auditor->record('payroll.loan.settle', $loan, null, ['method' => $method]);

            return $loan;
        });
    }

    /**
     * Review fix 1: a loan a run in approval (pending or approved) deducts
     * cannot change under it (409 `loan_on_run`). On a draft or calculated
     * run it may: that run is then refused at submission and posting
     * (`loan_changed`) until it is calculated again.
     */
    private function assertNotInFlow(Loan $loan): void
    {
        $inFlow = RunLine::query()->whereJsonContains('loans', [['loan_id' => $loan->id]])
            ->whereIn('run_id', Run::query()->whereIn('status', [Run::PENDING, Run::APPROVED])->select('id'))->exists();

        if ($inFlow) {
            throw Errors::make(409, 'loan_on_run');
        }
    }

    public function cancel(Loan $loan, ?string $reason, User $by): Loan
    {
        return Errors::transaction(function () use ($loan, $reason, $by) {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($loan->status !== Loan::ACTIVE || LoanEntry::query()->where('loan_id', $loan->id)->where('kind', '<>', LoanEntry::DISBURSEMENT)->exists()) {
                throw Errors::make(422, 'loan_started');
            }

            $this->assertNotInFlow($loan);

            LoanEntry::query()->create([
                'loan_id' => $loan->id, 'kind' => LoanEntry::REVERSAL, 'amount_minor' => -$loan->balance(),
                'entry_date' => CarbonImmutable::now()->toDateString(), 'note' => $reason, 'created_by' => $by->id,
            ]);
            $loan->fill(['status' => Loan::CANCELLED])->save();
            $this->auditor->record('payroll.loan.cancel', $loan, null, ['reason' => $reason]);

            return $loan;
        });
    }
}
