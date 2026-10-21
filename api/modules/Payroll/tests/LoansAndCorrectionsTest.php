<?php

namespace Modules\Payroll\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\LoanEntry;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PAY-13: loans and advances repaid by instalments, early settlement in
// the next run or outside payroll, cancellation; PAY-18: corrections of a
// posted run by reversal and by recalculation, posted as differences;
// PAY-23: amounts read-only by field rules.
class LoansAndCorrectionsTest extends TestCase
{
    use BuildsPayroll, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPayroll();
    }

    private function loan(string $employeeId, array $extra = []): array
    {
        return $this->postJson('/api/v1/payroll/loans', [
            'payroll_employee_id' => $employeeId, 'kind' => 'loan', 'reference' => 'L-1', 'principal_minor' => '1000000', 'instalment_minor' => '400000',
            'currency' => 'KES', 'disbursed_on' => '2026-09-20', 'starts_on' => '2026-10-01', ...$extra,
        ], $this->headersFor($this->officer))->assertCreated()->json('data');
    }

    public function test_instalments_are_deducted_until_the_loan_is_settled(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $loan = $this->loan($amina->id);
        $this->assertSame('1000000', $loan['balance']['amount_minor']);
        $this->assertSame('disbursement', $loan['entries'][0]['kind']);

        $october = $this->postedRun('2026-10-15');
        $line = $this->lines($october)[$amina->number];
        $this->assertSame('400000', $line['loans']['amount_minor']);
        $this->assertSame('3410000', $line['net']['amount_minor']);

        $this->postedRun('2026-11-15');
        // The last instalment is what is left: 200,000.
        $december = $this->calculatedRun('2026-12-15');
        $this->assertSame('200000', $this->lines($december)[$amina->number]['loans']['amount_minor']);

        $this->getJson("/api/v1/payroll/loans/{$loan['id']}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.balance.amount_minor', '200000')
            ->assertJsonPath('data.status', 'active');
        $this->assertSame(['disbursement', 'repayment', 'repayment'], $this->inTenant(fn () => LoanEntry::query()->where('loan_id', $loan['id'])->orderBy('created_at')->pluck('kind')->all()));
        // Reading a loan is audited (PAY-23).
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'payroll.pay.view')->where('auditable_id', $loan['id'])->exists()));

        // The ledger is append-only.
        $this->expectExceptionMessage('append-only');
        $this->inTenant(fn () => LoanEntry::query()->where('loan_id', $loan['id'])->update(['amount_minor' => 1]));
    }

    public function test_early_settlement_in_the_next_run_or_outside_payroll_and_cancellation(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $loan = $this->loan($amina->id);
        $this->postJson("/api/v1/payroll/loans/{$loan['id']}/settle", ['method' => 'next_run'], $this->headersFor($this->officer))->assertOk()->assertJsonPath('data.settle_next_run', true);

        $october = $this->postedRun();
        $this->assertSame('1000000', $this->lines($october)[$amina->number]['loans']['amount_minor']);
        $this->getJson("/api/v1/payroll/loans/{$loan['id']}", $this->headersFor($this->officer))->assertJsonPath('data.status', 'settled')->assertJsonPath('data.balance.amount_minor', '0');
        $this->postJson("/api/v1/payroll/loans/{$loan['id']}/settle", ['method' => 'external'], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'loan_settled');

        // Paid back outside payroll.
        $advance = $this->loan($amina->id, ['kind' => 'advance', 'reference' => 'A-1']);
        $this->postJson("/api/v1/payroll/loans/{$advance['id']}/settle", ['method' => 'external', 'date' => '2026-11-02', 'note' => 'Cash'], $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.status', 'settled')->assertJsonPath('data.balance.amount_minor', '0');

        // Cancelled before any repayment.
        $mistake = $this->loan($amina->id, ['reference' => 'L-2']);
        $this->postJson("/api/v1/payroll/loans/{$mistake['id']}/cancel", ['reason' => 'Typed twice'], $this->headersFor($this->officer))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $november = $this->calculatedRun('2026-11-15');
        $this->assertSame('0', $this->lines($november)[$amina->number]['loans']['amount_minor']);

        // An instalment above the principal, and another currency, are refused.
        $this->postJson('/api/v1/payroll/loans', ['payroll_employee_id' => $amina->id, 'kind' => 'loan', 'principal_minor' => '100', 'instalment_minor' => '200', 'currency' => 'KES', 'disbursed_on' => '2026-10-01'], $this->headersFor($this->officer))
            ->assertUnprocessable()->assertJsonPath('code', 'instalment_above_principal');
    }

    public function test_loans_need_permission_at_the_place_and_editable_amounts(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $loan = $this->loan($amina->id);

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson("/api/v1/payroll/loans/{$loan['id']}", $this->headersFor($manager))->assertNotFound();
        $this->getJson('/api/v1/payroll/loans', $this->headersFor($manager))->assertForbidden();
        $this->postJson('/api/v1/payroll/loans', ['payroll_employee_id' => $amina->id], $this->headersFor($manager))->assertForbidden();

        // RBAC-05: a role whose `amounts` are read-only reads loans but can't change them; hidden: no amounts at all.
        $limited = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $role = $this->role('Payroll clerk', ['payroll.loan.view', 'payroll.loan.manage']);
            $this->assign($user, $role, Scope::company($this->acme->id));
            FieldRule::query()->create(['role_id' => $role->id, 'resource' => 'payroll', 'field' => 'amounts', 'mode' => FieldRule::READONLY]);

            return $user;
        });
        $this->getJson("/api/v1/payroll/loans/{$loan['id']}", $this->headersFor($limited))->assertOk()->assertJsonPath('data.balance.amount_minor', '1000000');
        $this->postJson("/api/v1/payroll/loans/{$loan['id']}/settle", ['method' => 'external'], $this->headersFor($limited))->assertUnprocessable()->assertJsonPath('code', 'field_readonly');

        $this->inTenant(fn () => FieldRule::query()->update(['mode' => FieldRule::HIDDEN]));
        $hidden = $this->getJson('/api/v1/payroll/loans', $this->headersFor($limited))->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('balance', $hidden);
        $this->assertArrayNotHasKey('principal', $hidden);

        // Another tenant's loan is not found.
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('payroll'));
        $this->getJson("/api/v1/payroll/loans/{$loan['id']}", $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_a_reversal_correction_negates_what_was_posted_and_gives_loans_back(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $baraka = $this->payrollEmployee('3000000', ['first_name' => 'Baraka']);
        $loan = $this->loan($amina->id);
        $october = $this->postedRun();

        $correction = $this->postJson("/api/v1/payroll/runs/{$october}/corrections", ['mode' => 'reverse', 'employee_ids' => [$amina->id], 'pay_date' => '2026-11-05'], $this->headersFor($this->officer))
            ->assertCreated()->assertJsonPath('data.kind', 'correction')->assertJsonPath('data.corrects_run_id', $october)->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$correction}/calculate", [], $this->headersFor($this->officer))->assertOk();
        $lines = $this->lines($correction);

        $this->assertSame([$amina->number], array_keys($lines));
        $this->assertSame('-5000000', $lines[$amina->number]['gross']['amount_minor']);
        $this->assertSame('-3410000', $lines[$amina->number]['net']['amount_minor']);
        $this->assertSame('-400000', $lines[$amina->number]['loan_lines'][0]['amount']['amount_minor']);

        $this->postJson("/api/v1/payroll/runs/{$correction}/submit", [], $this->headersFor($this->officer))->assertOk();
        $this->approve($correction)->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$correction}/post", [], $this->headersFor($this->officer))->assertOk();

        // The instalment is given back to the loan.
        $this->getJson("/api/v1/payroll/loans/{$loan['id']}", $this->headersFor($this->officer))->assertJsonPath('data.balance.amount_minor', '1000000');
        // The original stays as it was.
        $this->assertSame(Run::POSTED, $this->inTenant(fn () => Run::query()->whereKey($october)->value('status')));
        $this->assertSame('3410000', $this->lines($october)[$amina->number]['net']['amount_minor']);
        // Only posted runs are corrected.
        $draft = $this->createRun('2026-11-15')->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$draft}/corrections", ['mode' => 'reverse'], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'run_not_posted');
    }

    public function test_a_settlement_outside_payroll_is_dated_on_the_companys_day_not_utcs(): void
    {
        // PAY-13: 00:30 on Tuesday 20 October 2026 in Nairobi; still Monday 19 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-19T21:30:00Z'));
        $advance = $this->loan($this->payrollEmployee('5000000')->id, ['kind' => 'advance', 'reference' => 'A-1']);
        $this->postJson("/api/v1/payroll/loans/{$advance['id']}/settle", ['method' => 'external', 'note' => 'Cash'], $this->headersFor($this->officer))->assertOk();
        $this->assertSame(['2026-09-20', '2026-10-20'], $this->entryDates($advance['id']));
    }

    public function test_a_cancellation_is_dated_on_the_companys_day_not_utcs(): void
    {
        // PAY-13: 00:30 on Tuesday 20 October 2026 in Nairobi; still Monday 19 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-19T21:30:00Z'));
        $mistake = $this->loan($this->payrollEmployee('5000000')->id, ['reference' => 'L-2']);
        $this->postJson("/api/v1/payroll/loans/{$mistake['id']}/cancel", ['reason' => 'Typed twice'], $this->headersFor($this->officer))->assertOk();
        $this->assertSame(['2026-09-20', '2026-10-20'], $this->entryDates($mistake['id']));
    }

    public function test_a_correction_is_paid_on_the_companys_day_by_default_not_utcs(): void
    {
        // PAY-18: 00:30 on Tuesday 20 October 2026 in Nairobi; still Monday 19 October in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-19T21:30:00Z'));
        $this->payrollEmployee('5000000');
        $october = $this->postedRun();
        $this->postJson("/api/v1/payroll/runs/{$october}/corrections", ['mode' => 'recalculate'], $this->headersFor($this->officer))->assertCreated()->assertJsonPath('data.pay_date', '2026-10-20');
    }

    /** @return list<string> */
    private function entryDates(string $loanId): array
    {
        $dates = array_column($this->getJson("/api/v1/payroll/loans/{$loanId}", $this->headersFor($this->officer))->assertOk()->json('data.entries'), 'entry_date');
        sort($dates);

        return $dates;
    }

    public function test_a_recalculation_correction_carries_the_difference(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $october = $this->postedRun();
        // A missed allowance of KES 10,000 for October.
        $item = $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'HOUSE', 'name' => 'House allowance', 'kind' => 'earning', 'taxable' => true, 'contributory' => true], $this->headersFor($this->officer))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/employees/{$amina->id}/items", ['item_id' => $item, 'amount_minor' => '1000000', 'currency' => 'KES', 'recurrence' => 'once', 'period_date' => '2026-10-20'], $this->headersFor($this->officer))->assertCreated();

        $correction = $this->postJson("/api/v1/payroll/runs/{$october}/corrections", ['mode' => 'recalculate'], $this->headersFor($this->officer))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$correction}/calculate", [], $this->headersFor($this->officer))->assertOk();
        $line = $this->lines($correction)[$amina->number];

        $this->assertSame('1000000', $line['gross']['amount_minor']);
        $this->assertSame([['code' => 'HOUSE']], array_map(fn (array $e) => ['code' => $e['code']], $line['earnings']));
        // On fixture rates: taxable +1,000,000 less the extra SHIF (2 %) at 30 %: PAYE +294,000; SHIF +20,000; AHL +10,000; NSSF capped: 0.
        $statutory = collect($line['statutory_lines'])->keyBy('code');
        $this->assertSame('294000', $statutory['paye']['employee']['amount_minor']);
        $this->assertSame('20000', $statutory['shif']['employee']['amount_minor']);
        $this->assertArrayNotHasKey('nssf', $statutory->all());
        $this->assertSame('676000', $line['net']['amount_minor']);
    }
}
