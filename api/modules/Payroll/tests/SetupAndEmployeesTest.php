<?php

namespace Modules\Payroll\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Notification;
use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Models\PayrollContract;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PAY-01 to PAY-06, PAY-25, PAY-27: pay groups, items, the statutory pack
// screen, employees' pay setup and items; Payroll alone (HR's employee
// core, owner decision B) and with HR; permission-denied and other-tenant
// cases.
class SetupAndEmployeesTest extends TestCase
{
    use BuildsPayroll, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_pay_groups_and_items(): void
    {
        $this->setUpPayroll();
        $headers = $this->headersFor($this->officer);

        $weekly = $this->postJson('/api/v1/payroll/pay-groups', ['company_id' => $this->acme->id, 'code' => 'WEEKLY', 'name' => 'Casuals', 'frequency' => 'weekly', 'anchor_date' => '2026-10-05'], $headers)
            ->assertCreated()->assertJsonPath('data.frequency', 'weekly')->json('data.id');
        $this->postJson('/api/v1/payroll/pay-groups', ['company_id' => $this->acme->id, 'code' => 'B', 'name' => 'B', 'frequency' => 'biweekly'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/payroll/pay-groups', ['company_id' => $this->acme->id, 'code' => 'weekly', 'name' => 'Again', 'frequency' => 'monthly'], $headers)->assertUnprocessable()->assertJsonPath('code', 'code_taken');
        $this->patchJson("/api/v1/payroll/pay-groups/{$weekly}", ['name' => 'Casual staff', 'frequency' => 'monthly'], $headers)->assertUnprocessable();
        $this->patchJson("/api/v1/payroll/pay-groups/{$weekly}", ['name' => 'Casual staff'], $headers)->assertOk()->assertJsonPath('data.name', 'Casual staff');
        $this->assertCount(2, $this->getJson('/api/v1/payroll/pay-groups', $headers)->assertOk()->json('data'));
        $this->postJson("/api/v1/payroll/pay-groups/{$weekly}/archive", [], $headers)->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/payroll/pay-groups', $headers)->json('data'));
        // A run for an archived group is refused; weekly runs need the pack's rule for non-monthly pay (null in every pack).
        $this->postJson('/api/v1/payroll/runs', ['pay_group_id' => $weekly], $headers)->assertUnprocessable()->assertJsonPath('code', 'pay_group_archived');

        $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'HOUSE', 'name' => 'House allowance', 'kind' => 'earning', 'taxable' => true, 'contributory' => false], $headers)
            ->assertCreated()->assertJsonPath('data.taxable', true);
        $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'PENSION', 'name' => 'Pension', 'kind' => 'deduction', 'taxable' => true], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'item_flag_kind');
        $item = $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'PENSION', 'name' => 'Pension', 'kind' => 'deduction', 'pre_tax' => true, 'pre_tax_category' => 'pension'], $headers)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/payroll/items/{$item}", ['kind' => 'earning'], $headers)->assertUnprocessable();
        $this->postJson("/api/v1/payroll/items/{$item}/archive", [], $headers)->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'payroll.item.create')->exists()));

        // Without payroll.settings.*: refused or not found; another tenant's rows: not found.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson('/api/v1/payroll/pay-groups', $this->headersFor($manager))->assertForbidden();
        $this->patchJson("/api/v1/payroll/items/{$item}", ['name' => 'X'], $this->headersFor($manager))->assertNotFound();
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('payroll'));
        $this->patchJson("/api/v1/payroll/pay-groups/{$this->monthly->id}", ['name' => 'X'], $this->headersFor($other['user']))->assertNotFound();
        $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'X', 'name' => 'X', 'kind' => 'earning'], $this->headersFor($other['user']))->assertUnprocessable();
    }

    public function test_the_statutory_screen_lists_what_the_pack_lacks(): void
    {
        $this->setUpPayroll();
        $this->getJson("/api/v1/payroll/statutory/{$this->acme->id}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.ready', true)->assertJsonPath('data.currency', 'KES');

        // A pack whose PAYE values are all null (as the packs shipped before the researched values).
        $pack = json_decode((string) file_get_contents(base_path('country-packs/KE/payroll.json')), true);
        $pack['components'][0]['periods'] = [['effective_from' => '2026-01-01', 'effective_to' => null, 'needs_confirmation' => true, 'bands' => null, 'rounding' => null]];
        app(PayrollPack::class)->fake('KE', $pack);
        $data = $this->getJson("/api/v1/payroll/statutory/{$this->acme->id}", $this->headersFor($this->officer))->assertOk()->json('data');
        $this->assertFalse($data['ready']);
        $this->assertSame('PAYE', $data['components'][0]['name']);
        $this->assertContains('bands', array_column($data['components'][0]['missing'], 'field'));
        $this->assertNotEmpty($data['todo']);
    }

    public function test_employees_pay_setup_and_items(): void
    {
        $this->setUpPayroll();
        $amina = $this->payrollEmployee('5000000');
        $headers = $this->headersFor($this->officer);

        // The copy follows HR (EmployeeHired, ContractChanged): number, name, place, contract.
        $this->getJson("/api/v1/payroll/employees/{$amina->id}", $headers)->assertOk()
            ->assertJsonPath('data.name', 'Amina Otieno')
            ->assertJsonPath('data.department.id', $this->sales->id)
            ->assertJsonPath('data.pay_group.id', $this->monthly->id)
            ->assertJsonPath('data.contract.pay.amount_minor', '5000000');
        $this->assertSame(1, $this->inTenant(fn () => PayrollContract::query()->count()));
        $this->assertCount(1, $this->getJson('/api/v1/payroll/employees?pay_group='.$this->monthly->id, $headers)->json('data'));
        $csv = $this->get('/api/v1/payroll/employees?format=csv', $headers)->assertOk();
        $this->assertStringContainsString('Amina Otieno', $csv->streamedContent());

        // Another company's pay group is refused; dependants and the hold flag are kept.
        $otherCompany = $this->inTenant(fn () => $this->company('Other Co'));
        $foreign = $this->postJson('/api/v1/payroll/pay-groups', ['company_id' => $otherCompany->id, 'code' => 'M', 'name' => 'M', 'frequency' => 'monthly'], $this->headersFor())->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/payroll/employees/{$amina->id}", ['pay_group_id' => $foreign], $headers)->assertUnprocessable();
        $this->patchJson("/api/v1/payroll/employees/{$amina->id}", ['dependants' => 2, 'on_hold' => true], $headers)->assertOk()->assertJsonPath('data.dependants', 2);
        // Held employees are left out of runs.
        $run = $this->calculatedRun();
        $this->assertSame([], $this->lines($run));
        $this->patchJson("/api/v1/payroll/employees/{$amina->id}", ['on_hold' => false], $headers)->assertOk();

        // Items: recurring and one-off; a paid one-off is frozen.
        $item = $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'BONUS', 'name' => 'Bonus', 'kind' => 'earning', 'taxable' => true, 'contributory' => true], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/employees/{$amina->id}/items", ['item_id' => $item, 'amount_minor' => '100000', 'currency' => 'USD', 'recurrence' => 'once', 'period_date' => '2026-10-10'], $headers)->assertUnprocessable();
        $once = $this->postJson("/api/v1/payroll/employees/{$amina->id}/items", ['item_id' => $item, 'amount_minor' => '100000', 'currency' => 'KES', 'recurrence' => 'once', 'period_date' => '2026-10-10'], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate", [], $headers)->assertOk();
        $this->assertSame('5100000', $this->lines($run)[$amina->number]['gross']['amount_minor']);
        $this->postJson("/api/v1/payroll/runs/{$run}/submit", [], $headers)->assertOk();
        $this->approve($run)->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$run}/post", [], $headers)->assertOk();
        $this->assertSame($run, $this->inTenant(fn () => EmployeeItem::query()->whereKey($once)->value('consumed_run_id')));
        $this->patchJson("/api/v1/payroll/employee-items/{$once}", ['amount_minor' => '200000'], $headers)->assertUnprocessable()->assertJsonPath('code', 'item_paid');
        $this->assertCount(1, $this->getJson("/api/v1/payroll/employees/{$amina->id}/items", $headers)->json('data'));

        // The employee's place decides: a branch-B payroll clerk does not find a branch-A employee.
        $clerkB = $this->userWith('payroll_officer', Scope::branch($this->branchB->id));
        $this->getJson("/api/v1/payroll/employees/{$amina->id}", $this->headersFor($clerkB))->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/payroll/employees', $this->headersFor($clerkB))->json('data'));
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('payroll'));
        $this->getJson("/api/v1/payroll/employees/{$amina->id}", $this->headersFor($other['user']))->assertNotFound();
        $this->patchJson("/api/v1/payroll/employee-items/{$once}", ['note' => 'x'], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_payroll_alone_brings_hr_employee_core_and_pays(): void
    {
        $this->setUpPayroll(withHr: false);
        $amina = $this->payrollEmployee('5000000');

        // Decision B: employees, contracts and positions open; the rest of HR stays closed.
        $this->postJson('/api/v1/hr/positions', ['company_id' => $this->acme->id, 'code' => 'CLK', 'title' => 'Clerk'], $this->headersFor())->assertCreated();
        $this->getJson('/api/v1/hr/leave-requests', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');

        $id = $this->postedRun();
        $this->assertSame('3810000', $this->lines($id)[$amina->number]['net']['amount_minor']);
    }

    public function test_with_hr_but_without_payroll_nothing_is_copied_until_payroll_syncs(): void
    {
        $this->setUpPayroll();
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('payroll'));
        $this->postJson('/api/v1/hr/employees', ['company_id' => $this->acme->id, 'first_name' => 'Zawadi', 'last_name' => 'K', 'hire_date' => '2026-01-05'], $this->headersFor())->assertCreated();
        $this->assertSame(0, $this->inTenant(fn () => PayrollEmployee::query()->count()));

        // Switched on later: the copy is made from HR's read contract.
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('payroll'));
        $this->postJson('/api/v1/payroll/employees/sync', ['company_id' => $this->acme->id], $this->headersFor($this->officer))->assertOk()->assertJsonPath('data.employees', 1);
        $this->assertSame('Zawadi K', $this->inTenant(fn () => PayrollEmployee::query()->value('name')));
    }
}
