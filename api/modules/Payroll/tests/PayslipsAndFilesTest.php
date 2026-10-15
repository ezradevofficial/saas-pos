<?php

namespace Modules\Payroll\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Models\Employee;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Modules\Payroll\Tests\Concerns\FixturePacks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PAY-19, PAY-24: payslips seen only by the employee and payroll readers
// (field rules applied), audited; PAY-20: payment files from HR's audited
// pay accounts; PAY-21: statutory returns with HR's identifiers, flagged
// while their layout is unconfirmed.
class PayslipsAndFilesTest extends TestCase
{
    use BuildsPayroll, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPayroll();
    }

    /** A colleague linked to $employee's HR record (self-service, HR-31). */
    private function linkedUser(string $hrEmployeeId)
    {
        return $this->inTenant(function () use ($hrEmployeeId) {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->roles->get('employee_self_service'), Scope::company($this->acme->id));
            Employee::query()->whereKey($hrEmployeeId)->update(['user_id' => $user->id]);

            return $user;
        });
    }

    public function test_payslips_are_seen_by_the_employee_and_payroll_readers_only(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $baraka = $this->payrollEmployee('3000000', ['first_name' => 'Baraka']);
        $aminaUser = $this->linkedUser($amina->employee_id);
        $barakaUser = $this->linkedUser($baraka->employee_id);
        $this->postedRun();
        $payslip = $this->inTenant(fn () => Payslip::query()->where('payroll_employee_id', $amina->id)->sole());

        // My payslips.
        $mine = $this->getJson('/api/v1/payroll/me/payslips', $this->headersFor($aminaUser))->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame($payslip->id, $mine[0]['id']);
        $this->getJson("/api/v1/payroll/me/payslips/{$payslip->id}", $this->headersFor($aminaUser))->assertOk()
            ->assertJsonPath('data.line.net.amount_minor', '3810000');
        $pdf = $this->get("/api/v1/payroll/me/payslips/{$payslip->id}/pdf", $this->headersFor($aminaUser))->assertOk();
        $this->assertStringStartsWith('application/pdf', $pdf->headers->get('Content-Type'));

        // A colleague never sees it, as themself or through the payroll routes.
        $this->getJson("/api/v1/payroll/me/payslips/{$payslip->id}", $this->headersFor($barakaUser))->assertNotFound();
        $this->getJson("/api/v1/payroll/payslips/{$payslip->id}", $this->headersFor($barakaUser))->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/v1/payroll/me/payslips', $this->headersFor($barakaUser))->json('data'));
        // Someone who is not an employee has none.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson('/api/v1/payroll/me/payslips', $this->headersFor($manager))->assertForbidden()->assertJsonPath('code', 'not_an_employee');
        $this->getJson("/api/v1/payroll/payslips/{$payslip->id}", $this->headersFor($manager))->assertNotFound();
        $this->getJson('/api/v1/payroll/payslips', $this->headersFor($manager))->assertForbidden();

        // Payroll readers see them all.
        $this->assertCount(2, $this->getJson('/api/v1/payroll/payslips', $this->headersFor($this->officer))->assertOk()->json('data'));
        $this->getJson("/api/v1/payroll/payslips/{$payslip->id}", $this->headersFor($this->officer))->assertOk()->assertJsonPath('data.employee.number', $amina->number);

        // Audited: views and downloads.
        $actions = $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $payslip->id)->pluck('action')->all());
        $this->assertContains('payroll.payslip.view', $actions);
        $this->assertContains('payroll.payslip.download', $actions);

        // RBAC-05: a payroll reader whose field rules hide amounts sees no payslip.
        $this->inTenant(fn () => FieldRule::query()->create(['role_id' => $this->roles->get('payroll_officer')->id, 'resource' => 'payroll', 'field' => 'amounts', 'mode' => FieldRule::HIDDEN]));
        $this->getJson("/api/v1/payroll/payslips/{$payslip->id}", $this->headersFor($this->officer))->assertNotFound();
        $this->getJson('/api/v1/payroll/payslips', $this->headersFor($this->officer))->assertForbidden();
        // Their own payslip stays theirs whatever their roles hide.
        $this->getJson("/api/v1/payroll/me/payslips/{$payslip->id}", $this->headersFor($aminaUser))->assertOk()->assertJsonPath('data.line.net.amount_minor', '3810000');

        // Another tenant sees nothing.
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('payroll'));
        $this->getJson("/api/v1/payroll/payslips/{$payslip->id}", $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_payment_files_pay_from_hr_accounts_and_list_who_is_left_out(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $baraka = $this->payrollEmployee('3000000', ['first_name' => 'Baraka'], setup: ['payment_method' => 'mobile_money']);
        $this->payrollEmployee('2000000', ['first_name' => 'Chiku'], setup: ['payment_method' => 'cash']);
        $this->postJson("/api/v1/hr/employees/{$amina->employee_id}/pay-accounts", [
            'kind' => 'bank', 'provider' => 'Equity', 'branch_code' => '068', 'account_name' => 'Amina Otieno', 'account_number' => '0123456789012', 'currency' => 'KES', 'is_primary' => true,
        ], $this->headersFor())->assertCreated();
        $id = $this->calculatedRun();

        $this->get("/api/v1/payroll/runs/{$id}/payment-file?format=generic_csv", $this->headersFor($this->officer))->assertUnprocessable();
        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headersFor($this->officer))->assertOk();
        $this->approve($id)->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/post", [], $this->headersFor($this->officer))->assertOk();

        $summary = $this->getJson("/api/v1/payroll/runs/{$id}/payment-summary?format=generic_csv", $this->headersFor($this->officer))->assertOk()->json('data');
        $this->assertSame(1, $summary['employees']);
        $this->assertSame(['KES' => '3810000'], $summary['totals']);
        $this->assertSame(1, $summary['cash']);
        $this->assertSame([['number' => $baraka->number, 'name' => $baraka->name, 'reason' => 'no_account']], array_map(fn ($m) => array_diff_key($m, ['message' => 1]), $summary['missing']));

        $file = $this->get("/api/v1/payroll/runs/{$id}/payment-file?format=generic_csv", $this->headersFor($this->officer))->assertOk();
        $this->assertStringContainsString('0123456789012', $file->getContent());
        $this->assertStringContainsString('38100.00', $file->getContent());
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'payroll.payment_file.generate')->where('auditable_id', $id)->exists()));
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_read')->exists()));

        // M-Pesa: the layout comes from the pack; without one the file is refused, with one it is flagged.
        $this->postJson("/api/v1/hr/employees/{$baraka->employee_id}/pay-accounts", [
            'kind' => 'mobile_money', 'provider' => 'M-Pesa', 'account_name' => 'Baraka Otieno', 'account_number' => '254700000002', 'currency' => 'KES', 'is_primary' => true,
        ], $this->headersFor())->assertCreated();
        $mpesa = $this->get("/api/v1/payroll/runs/{$id}/payment-file?format=mpesa_b2c_bulk", $this->headersFor($this->officer))->assertOk();
        $this->assertStringContainsString('254700000002', $mpesa->getContent());
        $this->assertSame('1', $mpesa->headers->get('X-Needs-Confirmation'));
        $pack = FixturePacks::ke();
        $pack['payment_formats'] = ['mpesa_b2c_bulk' => ['columns' => null, 'needs_confirmation' => true]];
        app(PayrollPack::class)->fake('KE', $pack);
        $this->get("/api/v1/payroll/runs/{$id}/payment-file?format=mpesa_b2c_bulk", $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'format_needs_confirmation');
        $this->get("/api/v1/payroll/runs/{$id}/payment-file?format=nope", $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'format_unknown');

        // Bank details only for holders of hr.bank.view: a role exporting files without it is refused.
        $clerk = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Payments clerk', ['payroll.run.view', 'payroll.payment.export']), Scope::company($this->acme->id));

            return $user;
        });
        $this->get("/api/v1/payroll/runs/{$id}/payment-file?format=generic_csv", [...$this->headersFor($clerk), 'Accept' => 'application/json'])->assertForbidden();
    }

    public function test_statutory_returns_carry_hr_identifiers_and_are_flagged_until_confirmed(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $this->putJson("/api/v1/hr/employees/{$amina->employee_id}/identifiers", ['identifiers' => [['type' => 'kra_pin', 'value' => 'A123456789Z']]], $this->headersFor())->assertOk();
        $this->postedRun();

        $types = $this->getJson("/api/v1/payroll/returns/types?company={$this->acme->id}", $this->headersFor($this->officer))->assertOk()->json('data');
        $this->assertSame(['paye', 'nssf', 'shif', 'ahl', 'nita', 'p9'], array_column($types, 'key'));
        $this->assertTrue($types[0]['needs_confirmation']);

        $return = $this->postJson('/api/v1/payroll/returns', ['company_id' => $this->acme->id, 'type' => 'paye', 'period' => '2026-10'], $this->headersFor($this->officer))->assertCreated()
            ->assertJsonPath('data.employee_count', 1)
            ->assertJsonPath('data.needs_confirmation', true)
            ->assertJsonPath('data.totals.0.employee.amount_minor', '940000')
            ->json('data');

        $file = $this->get("/api/v1/payroll/returns/{$return['id']}/file", $this->headersFor($this->officer))->assertOk();
        $this->assertStringContainsString('A123456789Z', $file->getContent());
        $this->assertStringContainsString('draft-paye-2026-10.csv', $file->headers->get('Content-Disposition'));
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'payroll.return.download')->exists()));

        // A period without a posted run, and an unknown type.
        $this->postJson('/api/v1/payroll/returns', ['company_id' => $this->acme->id, 'type' => 'paye', 'period' => '2026-08'], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'return_no_runs');
        $this->postJson('/api/v1/payroll/returns', ['company_id' => $this->acme->id, 'type' => 'vat', 'period' => '2026-10'], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'return_type_unknown');

        // Without returns permissions: refused; another tenant: not found.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson('/api/v1/payroll/returns', $this->headersFor($manager))->assertForbidden();
        $this->get("/api/v1/payroll/returns/{$return['id']}/file", [...$this->headersFor($manager), 'Accept' => 'application/json'])->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/v1/payroll/returns', $this->headersFor($this->officer))->json('data'));
    }
}
