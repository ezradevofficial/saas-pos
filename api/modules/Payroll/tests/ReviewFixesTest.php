<?php

namespace Modules\Payroll\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Support\EnvironmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;
use Modules\HR\Models\AttendancePeriod;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\Payroll\Http\Requests\RunRequest;
use Modules\Payroll\Models\Attendance;
use Modules\Payroll\Models\LeaveDay;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Runs\Runs;
use Modules\Payroll\Statutory\MissingValues;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Modules\Payroll\Tests\Concerns\FixturePacks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// The stage 7C review of Payroll (docs/modules/payroll.md, decisions 21 to
// 28): each finding with a test that failed before its fix.
class ReviewFixesTest extends TestCase
{
    use BuildsPayroll, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPayroll();
    }

    private function loan(string $employeeId, string $principal = '400000', string $instalment = '400000'): string
    {
        return $this->postJson('/api/v1/payroll/loans', [
            'payroll_employee_id' => $employeeId, 'kind' => 'loan', 'principal_minor' => $principal, 'instalment_minor' => $instalment,
            'currency' => 'KES', 'disbursed_on' => '2026-09-20', 'starts_on' => '2026-10-01',
        ], $this->headersFor($this->officer))->assertCreated()->json('data.id');
    }

    private function headers(): array
    {
        return $this->headersFor($this->officer);
    }

    // 1. Loans that change after calculate.

    public function test_a_loan_settled_after_calculation_refuses_the_run_until_it_is_calculated_again(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $loan = $this->loan($amina->id);
        $run = $this->calculatedRun();

        // Settled outside payroll while the run is only calculated: allowed, but the run is stale.
        $this->postJson("/api/v1/payroll/loans/{$loan}/settle", ['method' => 'external'], $this->headers())->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$run}/submit", [], $this->headers())->assertStatus(409)->assertJsonPath('code', 'loan_changed');

        $this->postJson("/api/v1/payroll/runs/{$run}/calculate", [], $this->headers())->assertOk();
        $this->assertSame('0', $this->lines($run)[$amina->number]['loans']['amount_minor']);
        $this->postJson("/api/v1/payroll/runs/{$run}/submit", [], $this->headers())->assertOk();
        $this->approve($run)->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$run}/post", [], $this->headers())->assertOk();
    }

    public function test_a_loan_on_a_run_in_approval_cannot_be_settled_or_cancelled(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $loan = $this->loan($amina->id);
        $run = $this->calculatedRun();
        $this->postJson("/api/v1/payroll/runs/{$run}/submit", [], $this->headers())->assertOk();

        $this->postJson("/api/v1/payroll/loans/{$loan}/settle", ['method' => 'external'], $this->headers())->assertStatus(409)->assertJsonPath('code', 'loan_on_run');
        $this->postJson("/api/v1/payroll/loans/{$loan}/cancel", [], $this->headers())->assertStatus(409)->assertJsonPath('code', 'loan_on_run');
    }

    public function test_two_runs_deducting_the_last_instalment_post_once(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->loan($amina->id);
        $october = $this->calculatedRun('2026-10-15');
        $november = $this->calculatedRun('2026-11-15');
        $this->assertSame('400000', $this->lines($november)[$amina->number]['loans']['amount_minor']);

        foreach ([$october, $november] as $run) {
            $this->postJson("/api/v1/payroll/runs/{$run}/submit", [], $this->headers())->assertOk();
            $this->approve($run)->assertOk();
        }

        $this->postJson("/api/v1/payroll/runs/{$october}/post", [], $this->headers())->assertOk();
        // The balance is gone: November's deduction is refused, never written twice.
        $this->postJson("/api/v1/payroll/runs/{$november}/post", [], $this->headers())->assertStatus(409)->assertJsonPath('code', 'loan_changed');
        $this->assertSame(Run::APPROVED, $this->inTenant(fn () => Run::query()->whereKey($november)->value('status')));
        // An approved run refused at posting can be cancelled and prepared again.
        $this->postJson("/api/v1/payroll/runs/{$november}/cancel", ['reason' => 'Loan repaid'], $this->headers())->assertOk();
    }

    // 2. Double corrections.

    public function test_one_open_correction_per_period_and_a_stale_correction_is_refused(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $october = $this->postedRun();
        $first = $this->postJson("/api/v1/payroll/runs/{$october}/corrections", ['mode' => 'reverse'], $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$october}/corrections", ['mode' => 'recalculate'], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'correction_open');

        $this->postJson("/api/v1/payroll/runs/{$first}/calculate", [], $this->headers())->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$first}/submit", [], $this->headers())->assertOk();
        $this->approve($first)->assertOk();
        // What it corrected changed since it was calculated (as if another correction had posted).
        $this->inTenant(fn () => DB::table('payroll_run_lines')->where('run_id', $first)->update(['inputs' => json_encode(['corrects' => []])]));
        $this->postJson("/api/v1/payroll/runs/{$first}/post", [], $this->headers())->assertStatus(409)->assertJsonPath('code', 'correction_stale');
        $this->assertNotNull($amina);
    }

    // 3. Paid twice across pay groups.

    public function test_an_employee_already_in_another_groups_run_for_the_period_is_flagged(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->calculatedRun();
        $other = $this->inTenant(fn () => PayGroup::query()->create(['company_id' => $this->acme->id, 'code' => 'OTHER', 'name' => 'Other', 'frequency' => 'monthly']));
        $this->patchJson("/api/v1/payroll/employees/{$amina->id}", ['pay_group_id' => $other->id], $this->headers())->assertOk();

        $run = $this->postJson('/api/v1/payroll/runs', ['pay_group_id' => $other->id, 'period_date' => '2026-10-15'], $this->headers())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate", [], $this->headers())->assertOk();
        $this->assertSame('paid_in_other_run', $this->lines($run)[$amina->number]['errors'][0]['code']);
        $this->postJson("/api/v1/payroll/runs/{$run}/submit", [], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'run_has_errors');
    }

    public function test_a_groups_periods_never_move_once_it_has_runs_and_never_lose_their_anchor(): void
    {
        $weekly = $this->postJson('/api/v1/payroll/pay-groups', ['company_id' => $this->acme->id, 'code' => 'WK', 'name' => 'Weekly', 'frequency' => 'weekly', 'anchor_date' => '2026-10-05'], $this->headers())->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/payroll/pay-groups/{$weekly}", ['anchor_date' => null], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'anchor_required');
        $this->patchJson("/api/v1/payroll/pay-groups/{$weekly}", ['anchor_date' => '2026-10-06'], $this->headers())->assertOk();

        $this->postJson('/api/v1/payroll/runs', ['pay_group_id' => $weekly, 'period_date' => '2026-10-15'], $this->headers())->assertCreated();
        $this->patchJson("/api/v1/payroll/pay-groups/{$weekly}", ['anchor_date' => '2026-10-07'], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'pay_group_has_runs');
        $this->patchJson("/api/v1/payroll/pay-groups/{$weekly}", ['frequency' => 'monthly'], $this->headers())->assertUnprocessable();
    }

    // 5. PAYROLL_PACK_DIR.

    public function test_the_pack_directory_is_honoured_only_in_development_and_staging_and_runs_say_which_pack(): void
    {
        $this->payrollEmployee('5000000');
        $id = $this->calculatedRun();
        $this->getJson("/api/v1/payroll/runs/{$id}", $this->headers())->assertJsonPath('data.pack.source', 'test')
            ->assertJsonPath('data.pack.sha256', hash('sha256', json_encode(FixturePacks::ke())));

        $dir = sys_get_temp_dir().'/payroll-packs-'.uniqid();
        mkdir("{$dir}/KE", 0777, true);
        file_put_contents("{$dir}/KE/payroll.json", json_encode(['currency' => 'KES', 'components' => [], 'marker' => 1]));
        config(['payroll.pack_dir' => $dir]);
        $packs = new PayrollPack;
        $this->assertSame(1, $packs->of('KE')['marker']);
        $this->assertSame('override', $packs->source('KE')['source']);

        // Review fix 11: a changed file is read again (no restart).
        file_put_contents("{$dir}/KE/payroll.json", json_encode(['currency' => 'KES', 'components' => [], 'marker' => 22]));
        touch("{$dir}/KE/payroll.json", time() + 5);
        $this->assertSame(22, $packs->of('KE')['marker']);

        // Production ignores it and the boot check refuses it.
        $this->app['env'] = 'production';

        try {
            $this->assertSame('shipped', (new PayrollPack)->source('KE')['source']);
            $this->assertNull((new PayrollPack)->of('KE')['marker'] ?? null);
            config(['app.env' => 'production']);
            $this->assertNotNull(EnvironmentGuard::checks(config())['Payroll pack directory']);
            config(['app.env' => 'staging']);
            $this->assertNull(EnvironmentGuard::checks(config())['Payroll pack directory']);
        } finally {
            $this->app['env'] = 'testing';
            config(['app.env' => 'testing', 'payroll.pack_dir' => null]);
        }
    }

    // 6. Unaudited pay reads.

    public function test_lists_showing_pay_are_audited(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->loan($amina->id);
        $this->postedRun();

        $this->getJson('/api/v1/payroll/loans', $this->headers())->assertOk();
        $this->getJson('/api/v1/payroll/payslips', $this->headers())->assertOk();
        $this->getJson("/api/v1/payroll/employees/{$amina->id}/items", $this->headers())->assertOk();

        $contexts = $this->inTenant(fn () => AuditEntry::query()->where('action', 'payroll.pay.view')->get()->map(fn (AuditEntry $e) => $e->after['context'] ?? null)->all());
        foreach (['loans', 'payslips', 'employee_items'] as $context) {
            $this->assertContains($context, $contexts);
        }
    }

    // 7. Calculate race.

    public function test_a_run_submitted_while_its_calculation_waits_for_the_lock_is_not_recalculated(): void
    {
        $this->payrollEmployee('5000000');
        $id = $this->createRun()->assertCreated()->json('data.id');
        $flipped = false;
        // Another request submits the run between a status check outside the lock and the transaction.
        Event::listen(TransactionBeginning::class, function () use ($id, &$flipped) {
            if (! $flipped) {
                $flipped = true;
                DB::table('payroll_runs')->where('id', $id)->update(['status' => Run::PENDING]);
            }
        });

        $this->inTenant(function () use ($id) {
            try {
                app(Runs::class)->calculate(Run::query()->findOrFail($id), $this->officer);
                $this->fail('A pending run was calculated.');
            } catch (ApiException $e) {
                $this->assertSame('run_status', $e->errorCode);
            }
        });
        $this->assertSame(0, $this->inTenant(fn () => RunLine::query()->where('run_id', $id)->count()));
    }

    // 8. Leave events.

    public function test_leave_cancelled_before_its_approval_arrives_stays_cancelled(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->putJson("/api/v1/payroll/settings/{$this->acme->id}", ['monthly_days' => '25'], $this->headers())->assertOk();
        $dates = [['date' => '2026-10-12', 'fraction' => '1.00']];
        $request = '0195e000-0000-7000-8000-0000000000c1';
        $type = '0195e000-0000-7000-8000-0000000000d1';

        // Queued listeners: the cancellation is handled first.
        event(new LeaveCancelled($amina->tenant_id, $request, $amina->employee_id, $this->acme->id, $type, '1.00', $dates));
        event(new LeaveApproved($amina->tenant_id, $request, 'LV-1', $amina->employee_id, $this->acme->id, $type, 'UNPAID', false, '2026-10-12', '2026-10-12', false, false, '1.00', $dates));

        $this->assertSame('5000000', $this->lines($this->calculatedRun())[$amina->number]['gross']['amount_minor']);
    }

    public function test_payroll_switched_on_after_hr_gets_unpaid_leave_and_closed_attendance_from_hr(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('payroll'));
        $id = $this->postJson('/api/v1/hr/employees', ['company_id' => $this->acme->id, 'first_name' => 'Zawadi', 'last_name' => 'K', 'hire_date' => '2026-01-05'], $this->headersFor())->assertCreated()->json('data.id');
        $this->inTenant(function () use ($id) {
            $type = LeaveType::query()->create(['company_id' => $this->acme->id, 'code' => 'UNPAID', 'name' => 'Unpaid', 'paid' => false, 'accrual' => 'none', 'count_basis' => 'calendar_days']);
            $employee = Employee::query()->findOrFail($id);
            LeaveRequest::query()->create([
                'number' => 'LV-T-1', 'employee_id' => $id, 'company_id' => $this->acme->id, 'branch_id' => $employee->branch_id, 'location_id' => $employee->location_id,
                'leave_type_id' => $type->id, 'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'start_half' => false, 'end_half' => false, 'days' => '2',
                'dates' => [['date' => '2026-10-12', 'fraction' => '1.00'], ['date' => '2026-10-13', 'fraction' => '1.00']], 'status' => LeaveRequest::APPROVED, 'decided_at' => now(), 'requested_by' => $this->owner->id,
            ]);
            AttendancePeriod::query()->create(['company_id' => $this->acme->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => AttendancePeriod::CLOSED, 'closed_at' => now(), 'totals' => [[
                'employee_id' => $id, 'days_present' => 20, 'days_absent' => 0, 'days_leave' => 0, 'worked_minutes' => 9600, 'regular_minutes' => 9540, 'overtime_minutes' => 60,
                'night_minutes' => 0, 'holiday_minutes' => 0, 'rest_day_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0,
            ]]]);
        });

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('payroll'));
        $this->postJson('/api/v1/payroll/employees/sync', ['company_id' => $this->acme->id], $this->headers())->assertOk();

        $this->assertSame(2, $this->inTenant(fn () => LeaveDay::query()->where('employee_id', $id)->whereNull('cancelled_at')->count()));
        $this->assertSame(60, $this->inTenant(fn () => Attendance::query()->where('employee_id', $id)->value('overtime_minutes')));
    }

    // 9. EmploymentContracts.

    public function test_contracts_are_read_only_for_a_run_or_a_holder_of_hr_pay_view(): void
    {
        $this->payrollEmployee('5000000');
        $clerk = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Payroll clerk', ['payroll.employee.view', 'payroll.employee.manage']), Scope::company($this->acme->id));

            return $user;
        });

        $this->postJson('/api/v1/payroll/employees/sync', ['company_id' => $this->acme->id], $this->headersFor($clerk))->assertForbidden();
        $this->postJson('/api/v1/payroll/employees/sync', ['company_id' => $this->acme->id], $this->headers())->assertOk();
        $read = $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.contract.payroll_read')->latest('occurred_at')->first());
        $this->assertSame('user', $read->after['context'] ?? null);
    }

    // 10. Payslip reader scope.

    public function test_a_payslip_stays_with_the_place_it_was_paid_at(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->postedRun();
        $payslip = $this->inTenant(fn () => Payslip::query()->sole());
        $readerA = $this->userWith('payroll_officer', Scope::branch($this->branchA->id));
        $readerB = $this->userWith('payroll_officer', Scope::branch($this->branchB->id));

        // The employee moves to branch B afterwards.
        $this->patchJson("/api/v1/hr/employees/{$amina->employee_id}", ['branch_id' => $this->branchB->id], $this->headersFor())->assertOk();
        $this->postJson('/api/v1/payroll/employees/sync', ['company_id' => $this->acme->id], $this->headers())->assertOk();

        $this->getJson("/api/v1/payroll/payslips/{$payslip->id}", $this->headersFor($readerA))->assertOk();
        $this->getJson("/api/v1/payroll/payslips/{$payslip->id}", $this->headersFor($readerB))->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/v1/payroll/payslips', $this->headersFor($readerA))->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/payroll/payslips', $this->headersFor($readerB))->json('data'));
    }

    // 11. The rest.

    public function test_overlapping_pack_periods_are_flagged(): void
    {
        $pack = FixturePacks::ke();
        $pack['components'][0]['periods'][] = [...$pack['components'][0]['periods'][0], 'effective_from' => '2026-06-01'];

        $this->assertSame([['component' => 'paye', 'fields' => ['periods_overlap']]], MissingValues::check($pack, CarbonImmutable::parse('2026-10-31'), 'monthly'));
    }

    public function test_payment_summaries_are_audited_and_files_and_pdfs_are_throttled(): void
    {
        $this->payrollEmployee('5000000');
        $id = $this->postedRun();
        $this->getJson("/api/v1/payroll/runs/{$id}/payment-summary", $this->headers())->assertOk();
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'payroll.payment_file.summary')->exists()));

        foreach (['payroll.runs.payment-summary', 'payroll.payslips.pdf', 'payroll.me.payslips.pdf', 'payroll.runs.payment-file'] as $name) {
            $this->assertContains('throttle:payroll-files', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $this->assertArrayHasKey('payroll.runs.payment-summary', RunRequest::PERMISSIONS);
    }
}
