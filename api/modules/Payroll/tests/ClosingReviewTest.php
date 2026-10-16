<?php

namespace Modules\Payroll\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\HR\Events\AttendancePeriodClosed;
use Modules\HR\Events\ContractChanged;
use Modules\HR\Events\LeaveApproved;
use Modules\Payroll\Employees\EmployeeCopy;
use Modules\Payroll\Listeners\FollowHr;
use Modules\Payroll\Models\Attendance;
use Modules\Payroll\Models\LeaveDay;
use Modules\Payroll\Models\PayrollContract;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// The stage 7C closing review of Payroll (docs/modules/payroll.md,
// decisions 29 to 40): each finding with a test that failed before its fix.
class ClosingReviewTest extends TestCase
{
    use BuildsPayroll, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPayroll();
    }

    private function headers(): array
    {
        return $this->headersFor($this->officer);
    }

    private function closePeriod(PayrollEmployee $employee, string $start, string $end, array $minutes, ?string $periodId = null): void
    {
        event(new AttendancePeriodClosed($employee->tenant_id, $periodId ?? (string) Str::uuid7(), $this->acme->id, $start, $end, 'Africa/Nairobi', null, [[
            'employee_id' => $employee->employee_id, 'days_present' => 20, 'days_absent' => 0, 'days_leave' => 0, 'worked_minutes' => 10000,
            'regular_minutes' => 9000, 'overtime_minutes' => 0, 'night_minutes' => 0, 'holiday_minutes' => 0, 'rest_day_minutes' => 0,
            'late_minutes' => 0, 'early_leave_minutes' => 0, ...$minutes,
        ]]));
    }

    private function unpaidLeave(PayrollEmployee $employee, string $date): void
    {
        event(new LeaveApproved($employee->tenant_id, (string) Str::uuid7(), 'LV-'.Str::random(4), $employee->employee_id, $this->acme->id, (string) Str::uuid7(), 'UNPAID', false,
            $date, $date, false, false, '1.00', [['date' => $date, 'fraction' => '1.00']]));
    }

    private function runData(string $id): array
    {
        return $this->getJson("/api/v1/payroll/runs/{$id}", $this->headers())->assertOk()->json('data');
    }

    // 1. Attendance closed after its run is paid as arrears; an open period is a warning; a period closed after calculation makes the run stale.

    public function test_attendance_closed_after_its_run_is_paid_as_arrears_by_the_next_run(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->putJson("/api/v1/payroll/settings/{$this->acme->id}", ['overtime_multiplier' => '1.5', 'monthly_hours' => '200', 'monthly_days' => '25'], $this->headers())->assertOk();
        $this->postedRun('2026-10-15');

        // October's attendance closes after October was paid: November pays it as arrears and marks it paid.
        $this->closePeriod($amina, '2026-10-01', '2026-10-31', ['overtime_minutes' => 600]);
        $november = $this->calculatedRun('2026-11-15');
        $line = $this->lines($november)[$amina->number];
        $arrears = collect($line['earnings'])->firstWhere('code', 'overtime_arrears');

        $this->assertSame('375000', $arrears['amount']['amount_minor']);
        $this->assertSame('Overtime (arrears)', $arrears['name']);
        $this->assertSame('2026-10-31', $line['inputs']['arrears'][0]['end_date']);

        $this->postJson("/api/v1/payroll/runs/{$november}/submit", [], $this->headers())->assertOk();
        $this->approve($november)->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$november}/post", [], $this->headers())->assertOk();
        $this->assertSame($november, $this->inTenant(fn () => Attendance::query()->where('employee_id', $amina->employee_id)->value('paid_run_id')));
    }

    public function test_history_from_before_the_first_run_is_not_paid_as_arrears(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->putJson("/api/v1/payroll/settings/{$this->acme->id}", ['overtime_multiplier' => '1.5', 'monthly_hours' => '200'], $this->headers())->assertOk();
        $this->closePeriod($amina, '2026-09-01', '2026-09-30', ['overtime_minutes' => 600]);

        $line = $this->lines($this->calculatedRun('2026-10-15'))[$amina->number];
        $this->assertNull(collect($line['earnings'])->firstWhere('code', 'overtime_arrears'));
    }

    public function test_an_open_attendance_period_overlapping_the_run_is_a_warning(): void
    {
        $this->payrollEmployee('5000000');
        $this->postJson('/api/v1/hr/attendance-periods', ['company_id' => $this->acme->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'], $this->headersFor())->assertCreated();

        $run = $this->runData($this->calculatedRun('2026-10-15'));
        $this->assertEquals([['start_date' => '2026-10-01', 'end_date' => '2026-10-31']], $run['review']['attendance_open']);
    }

    public function test_a_period_closed_after_calculation_makes_the_run_stale(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $id = $this->calculatedRun('2026-10-15');
        $this->closePeriod($amina, '2026-10-01', '2026-10-31', ['overtime_minutes' => 60]);

        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headers())->assertStatus(409)->assertJsonPath('code', 'run_stale');
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headers())->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headers())->assertOk();
    }

    // 3. The HR sync and its audit rows commit before the calculation starts.

    public function test_the_hr_sync_commits_before_the_calculation_so_the_audit_lock_is_not_held(): void
    {
        $this->payrollEmployee('5000000');
        $id = $this->createRun('2026-10-15')->assertCreated()->json('data.id');
        $state = ['read_committed' => false, 'when_calculating' => null];

        DB::listen(function ($query) use (&$state) {
            if (str_starts_with($query->sql, 'insert into "audit_logs"') && in_array('hr.contract.payroll_read', $query->bindings, true)) {
                DB::connection('pgsql')->afterCommit(function () use (&$state) {
                    $state['read_committed'] = true;
                });
            }

            if ($state['when_calculating'] === null && str_starts_with($query->sql, 'delete from "payroll_run_lines"')) {
                $state['when_calculating'] = $state['read_committed'];
            }
        });

        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headers())->assertOk();
        $this->assertTrue($state['when_calculating']);
    }

    // 4 and 14. Incremental sync with bulk upserts; the calculation reads its inputs once.

    public function test_a_calculation_for_200_employees_with_24_closed_periods_stays_within_its_query_budget(): void
    {
        $hrIds = $this->inTenant(function () {
            $now = now();
            $employees = [];
            $contracts = [];

            for ($i = 1; $i <= 200; $i++) {
                $id = (string) Str::uuid7();
                $employees[] = ['id' => $id, 'tenant_id' => $this->acme->tenant_id, 'number' => sprintf('BULK-%03d', $i), 'company_id' => $this->acme->id, 'first_name' => 'Staff', 'last_name' => (string) $i,
                    'hire_date' => '2024-01-01', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
                $contracts[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->acme->tenant_id, 'employee_id' => $id, 'company_id' => $this->acme->id, 'type' => 'permanent', 'start_date' => '2024-01-01',
                    'pay_basis' => 'monthly', 'pay_minor' => 5000000, 'currency' => 'KES', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            }

            DB::table('hr_employees')->insert($employees);
            DB::table('hr_contracts')->insert($contracts);
            $start = now()->setDate(2024, 11, 1)->startOfDay();

            for ($p = 0; $p < 24; $p++) {
                $from = $start->copy()->addMonthsNoOverflow($p);
                DB::table('hr_attendance_periods')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->acme->tenant_id, 'company_id' => $this->acme->id,
                    'start_date' => $from->toDateString(), 'end_date' => $from->copy()->endOfMonth()->toDateString(), 'status' => 'closed', 'closed_at' => $now,
                    'totals' => json_encode(array_map(fn (array $e) => ['employee_id' => $e['id'], 'days_present' => 20, 'days_absent' => 0, 'days_leave' => 0, 'worked_minutes' => 9600,
                        'regular_minutes' => 9600, 'overtime_minutes' => 0, 'night_minutes' => 0, 'holiday_minutes' => 0, 'rest_day_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0], $employees)),
                    'created_at' => $now, 'updated_at' => $now]);
            }

            return array_column($employees, 'id');
        });

        $this->postJson('/api/v1/payroll/employees/sync', ['company_id' => $this->acme->id], $this->headers())->assertOk()->assertJsonPath('data.employees', 200);
        $this->inTenant(fn () => PayrollEmployee::query()->whereIn('employee_id', $hrIds)->update(['pay_group_id' => $this->monthly->id, 'payment_method' => 'bank']));
        $this->assertSame(200 * 24, $this->inTenant(fn () => Attendance::query()->count()));
        $id = $this->createRun('2026-10-15')->assertCreated()->json('data.id');

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headers())->assertOk()->assertJsonPath('data.employees', 200);
        $this->assertLessThan(120, $count, "The calculation ran {$count} queries.");

        // Calculating again reads nothing HR has not changed since.
        $count = 0;
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headers())->assertOk();
        $this->assertLessThan(120, $count, "The second calculation ran {$count} queries.");
        $this->assertSame(200, $this->inTenant(fn () => RunLine::query()->where('run_id', $id)->count()));
    }

    // 5 and 7. Corrections: the posted line's department; another currency is a line error.

    public function test_a_correction_charges_the_department_the_posted_line_charged(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $october = $this->postedRun('2026-10-15');
        $kitchen = $this->inTenant(fn () => Department::create(['company_id' => $this->acme->id, 'code' => 'KITCHEN', 'name' => 'Kitchen']));
        $this->patchJson("/api/v1/hr/employees/{$amina->employee_id}", ['department_id' => $kitchen->id], $this->headersFor())->assertOk();

        $correction = $this->postJson("/api/v1/payroll/runs/{$october}/corrections", ['mode' => 'reverse', 'pay_date' => '2026-10-30'], $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$correction}/calculate", [], $this->headers())->assertOk();

        $this->assertSame($this->sales->id, $this->inTenant(fn () => RunLine::query()->where('run_id', $correction)->value('department_id')));
    }

    public function test_a_recalculation_in_another_pay_currency_is_a_line_error(): void
    {
        $this->inTenant(fn () => TenantCurrency::query()->where('code', 'USD')->update(['active' => true]));
        $amina = $this->payrollEmployee('5000000');
        $october = $this->postedRun('2026-10-15');
        $this->patchJson("/api/v1/payroll/employees/{$amina->id}", ['pay_currency' => 'USD'], $this->headers())->assertOk();

        $correction = $this->postJson("/api/v1/payroll/runs/{$october}/corrections", ['mode' => 'recalculate', 'pay_date' => '2026-10-30'], $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$correction}/calculate", [], $this->headers())->assertOk();

        $this->assertContains('currency_mismatch', array_column($this->lines($correction)[$amina->number]['errors'], 'code'));
        $this->postJson("/api/v1/payroll/runs/{$correction}/submit", [], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'run_has_errors');
    }

    // 6. Late HR changes: before posting the run is stale; after posting the next run says so.

    public function test_leave_approved_after_calculation_refuses_the_run_and_after_posting_is_flagged_on_the_next_run(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->putJson("/api/v1/payroll/settings/{$this->acme->id}", ['monthly_days' => '25'], $this->headers())->assertOk();
        $id = $this->calculatedRun('2026-10-15');

        $this->unpaidLeave($amina, '2026-10-12');
        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headers())->assertStatus(409)->assertJsonPath('code', 'run_stale');

        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headers())->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headers())->assertOk();
        $this->approve($id)->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/post", [], $this->headers())->assertOk();

        // After posting: October's pay is not changed, the November run says a correction may be needed.
        $this->unpaidLeave($amina, '2026-10-13');
        $november = $this->runData($this->calculatedRun('2026-11-15'));
        $this->assertEquals([['number' => $amina->number, 'name' => $amina->name, 'run_number' => $this->runData($id)['number']]], $november['review']['late_changes']);
    }

    public function test_an_employee_without_a_pay_group_is_flagged_on_the_run(): void
    {
        $this->payrollEmployee('5000000');
        $baraka = $this->payrollEmployee('3000000', ['first_name' => 'Baraka'], setup: ['pay_group_id' => null]);

        $run = $this->runData($this->calculatedRun('2026-10-15'));
        $this->assertSame([$baraka->number], array_column($run['review']['no_pay_group'], 'number'));
    }

    // 9. A rehire in the period.

    public function test_a_rehire_in_the_same_period_pays_both_stretches(): void
    {
        $amina = $this->payrollEmployee('3100000');
        $this->postJson("/api/v1/hr/employees/{$amina->employee_id}/terminate", ['termination_date' => '2026-10-10', 'reason' => 'resignation'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/hr/employees/{$amina->employee_id}/rehire", ['hire_date' => '2026-10-20'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/hr/employees/{$amina->employee_id}/contracts", ['type' => 'permanent', 'start_date' => '2026-10-20', 'pay_basis' => 'monthly', 'pay_amount' => '31000.00', 'currency' => 'KES'], $this->headersFor())->assertCreated();

        // 10 days (1 to 10 October) and 12 days (20 to 31 October) of 31 at KES 31,000.
        $line = $this->lines($this->calculatedRun('2026-10-15'))[$amina->number];
        $this->assertSame('2200000', $line['basic']['amount_minor']);
    }

    // 11. Approvers see totals, department totals and variances, not per-employee lines.

    public function test_an_approver_sees_run_and_department_totals_but_not_the_lines(): void
    {
        $this->payrollEmployee('5000000');
        $id = $this->calculatedRun('2026-10-15');
        $approver = $this->userWith('approver', Scope::company($this->acme->id));

        $run = $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($approver))->assertOk()->json('data');
        $this->assertSame('Sales', $run['totals']['departments'][0]['name']);
        $this->assertSame('5000000', $run['totals']['departments'][0]['gross']['amount_minor']);
        $this->assertSame(1, $run['totals']['variance']['new']);
        $this->getJson("/api/v1/payroll/runs/{$id}/lines", $this->headersFor($approver))->assertForbidden();
        $this->getJson("/api/v1/payroll/runs/{$id}/lines", $this->headers())->assertOk();
    }

    // 13. Payment files: one sensitive read for the file; employees out of the reader's places are left out and listed.

    public function test_a_payment_file_reads_accounts_once_and_lists_employees_out_of_scope(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $juma = $this->payrollEmployee('4000000', ['first_name' => 'Juma', 'branch_id' => $this->branchB->id]);

        foreach ([$amina, $juma] as $employee) {
            $this->postJson("/api/v1/hr/employees/{$employee->employee_id}/pay-accounts", ['kind' => 'bank', 'provider' => 'Bank', 'account_name' => $employee->name, 'account_number' => '0123456789', 'currency' => 'KES', 'is_primary' => true], $this->headersFor())->assertCreated();
        }

        $id = $this->postedRun('2026-10-15');
        $before = $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_read')->count());

        $summary = $this->getJson("/api/v1/payroll/runs/{$id}/payment-summary", $this->headers())->assertOk()->json('data');
        $this->assertSame(2, $summary['employees']);
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_read')->count()) - $before);
        $read = $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_read')->latest('occurred_at')->first());
        $this->assertEqualsCanonicalizing([$amina->employee_id, $juma->employee_id], $read->after['employees']);

        // A reader holding the bank view at branch A only: Juma (branch B) is left out and listed, not a 403.
        $limited = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Payments A', ['payroll.run.view', 'payroll.payment.export']), Scope::company($this->acme->id));
            $this->assign($user, $this->role('Bank A', ['hr.bank.view']), Scope::branch($this->branchA->id));

            return $user;
        });
        $summary = $this->getJson("/api/v1/payroll/runs/{$id}/payment-summary", $this->headersFor($limited))->assertOk()->json('data');
        $this->assertSame(1, $summary['employees']);
        $this->assertSame([[$juma->number, 'out_of_scope']], array_map(fn (array $m) => [$m['number'], $m['reason']], $summary['missing']));
    }

    // 14. The rest.

    public function test_a_late_contract_changed_never_overwrites_a_newer_one(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $contract = $this->inTenant(fn () => PayrollContract::query()->where('employee_id', $amina->employee_id)->sole());
        $event = fn (string $pay, int $version) => new ContractChanged($amina->tenant_id, $contract->contract_id, $amina->employee_id, $this->acme->id, ContractChanged::UPDATED, 'permanent',
            '2026-01-05', null, null, 'monthly', $pay, 'KES', null, null, null, $version);

        app(FollowHr::class)->handle($event('6000000', 3));
        app(FollowHr::class)->handle($event('5500000', 2));

        $this->assertSame(6000000, $this->inTenant(fn () => PayrollContract::query()->whereKey($contract->id)->value('pay_minor')));
    }

    public function test_hr_counts_contract_versions_and_the_copy_keeps_the_version_it_copied(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $contract = $this->inTenant(fn () => PayrollContract::query()->where('employee_id', $amina->employee_id)->sole());
        $this->assertSame(1, (int) $contract->source_version);

        $this->patchJson("/api/v1/hr/contracts/{$contract->contract_id}", ['pay_amount' => '55000.00'], $this->headersFor())->assertOk();

        $copy = $this->inTenant(fn () => PayrollContract::query()->whereKey($contract->id)->sole());
        $this->assertSame([2, 5500000], [(int) $copy->source_version, $copy->pay_minor]);
    }

    public function test_leave_copied_while_another_sync_inserts_the_same_day_does_not_fail(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $request = (string) Str::uuid7();
        $injected = false;

        // A concurrent call commits the same day between this call's read and its write (once this call has written, nothing more is injected).
        DB::listen(function ($query) use (&$injected, $request, $amina) {
            if (! $injected && str_starts_with($query->sql, 'insert into payroll_leave_days')) {
                $injected = true;
            }

            if (! $injected && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'payroll_leave_days')) {
                $injected = true;
                DB::table('payroll_leave_days')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $amina->tenant_id, 'employee_id' => $amina->employee_id, 'company_id' => $this->acme->id,
                    'request_id' => $request, 'date' => '2026-10-12', 'fraction' => '1.00', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->inTenant(fn () => app(EmployeeCopy::class)->leaveApproved(new LeaveApproved($amina->tenant_id, $request, 'LV-1', $amina->employee_id, $this->acme->id, (string) Str::uuid7(), 'UNPAID', false,
            '2026-10-12', '2026-10-12', false, false, '1.00', [['date' => '2026-10-12', 'fraction' => '1.00']])));

        $this->assertSame(1, $this->inTenant(fn () => LeaveDay::query()->where('request_id', $request)->count()));
    }

    public function test_contract_pay_in_payroll_follows_hr_pay_view_and_the_hr_pay_field_rule(): void
    {
        $amina = $this->payrollEmployee('5000000');
        [$clerk, $viewer] = $this->inTenant(function () {
            $clerk = $this->colleague($this->owner);
            $this->assign($clerk, $this->role('Payroll clerk', ['payroll.employee.view']), Scope::company($this->acme->id));
            $viewer = $this->colleague($this->owner);
            $role = $this->role('Payroll viewer', ['payroll.employee.view', 'hr.pay.view']);
            $this->assign($viewer, $role, Scope::company($this->acme->id));

            return [$clerk, $viewer];
        });

        $this->getJson("/api/v1/payroll/employees/{$amina->id}", $this->headers())->assertOk()->assertJsonPath('data.contract.pay.amount_minor', '5000000');
        $this->assertArrayNotHasKey('contract', $this->getJson("/api/v1/payroll/employees/{$amina->id}", $this->headersFor($clerk))->assertOk()->json('data'));
        $this->getJson("/api/v1/payroll/employees/{$amina->id}", $this->headersFor($viewer))->assertOk()->assertJsonPath('data.contract.pay.amount_minor', '5000000');

        $this->inTenant(fn () => FieldRule::query()->create(['role_id' => $this->inTenant(fn () => Role::query()->where('name', 'Payroll viewer')->value('id')), 'resource' => 'hr_employee', 'field' => 'pay', 'mode' => FieldRule::HIDDEN]));
        $this->assertArrayNotHasKey('contract', $this->getJson("/api/v1/payroll/employees/{$amina->id}", $this->headersFor($viewer))->assertOk()->json('data'));
    }
}
