<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Events\ContractChanged;
use Modules\HR\Events\EmployeeHired;
use Modules\HR\Events\EmployeeTerminated;
use Modules\HR\Models\Contract;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveType;
use Modules\HR\Models\Position;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-01, HR-02, HR-05, HR-07, HR-08, HR-12, HR-33, HR-36, HR-37, HR-38:
// employees hired, changed, terminated and rehired at their place, with
// contracts and positions; module gating, permission-denied and other
// tenants' ids.
class EmployeesTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
    }

    public function test_hiring_numbers_the_employee_places_it_and_raises_employee_hired(): void
    {
        Event::fake([EmployeeHired::class]);

        $data = $this->hire([
            'location_id' => $this->locationA->id, 'department_id' => $this->sales->id, 'work_email' => 'amina@example.com',
            'emergency_contacts' => [['name' => 'Juma Otieno', 'relationship' => 'Brother', 'phone' => '+254700000001']],
            'clock_methods' => ['till', 'web', 'till'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.branch.id', $this->branchA->id)
            ->assertJsonPath('data.location.id', $this->locationA->id)
            ->assertJsonPath('data.department.code', 'SALES')
            ->assertJsonPath('data.clock_methods', ['till', 'web'])
            ->assertJsonPath('data.emergency_contacts.0.name', 'Juma Otieno')
            ->json('data');

        $this->assertSame('EMP-00001', $data['number']);
        $this->assertSame('EMP-00002', $this->hire(['first_name' => 'Baraka'])->assertCreated()->json('data.number'));
        Event::assertDispatched(EmployeeHired::class, fn (EmployeeHired $e) => $e->employeeId === $data['id'] && $e->hireDate === '2026-01-05' && ! $e->rehired && $e->locationId === $this->locationA->id);

        // HR-12: the company's first hire copied its country's statutory leave types, days needed.
        $types = $this->inTenant(fn () => LeaveType::query()->orderBy('code')->get());
        $this->assertSame(['annual', 'maternity', 'paternity', 'sick'], $types->pluck('statutory_key')->sort()->values()->all());
        $this->assertTrue($types->every(fn (LeaveType $t) => $t->needs_confirmation && $t->entitlement_days === null));

        // HR-37: audited.
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.create')->where('auditable_id', $data['id'])->exists()));
    }

    public function test_places_departments_positions_and_managers_must_be_of_the_company(): void
    {
        $other = $this->inTenant(fn () => $this->company('Other Co'));
        $otherBranch = $this->inTenant(fn () => $this->branch($other, 'X'));
        $otherPosition = $this->inTenant(fn () => Position::create(['company_id' => $other->id, 'code' => 'CLK', 'title' => 'Clerk']));

        // The owner may hire anywhere: the place itself is refused. The company's HR Officer is not allowed there at all.
        $this->hire(['branch_id' => $otherBranch->id], $this->owner)->assertUnprocessable()->assertJsonPath('code', 'place_other_company');
        $this->hire(['branch_id' => $otherBranch->id])->assertForbidden();
        $this->hire(['position_id' => $otherPosition->id])->assertUnprocessable()->assertJsonPath('code', 'position_other_company');

        $boss = $this->employee(['first_name' => 'Boss']);
        $worker = $this->employee(['first_name' => 'Worker', 'reports_to_id' => $boss->id]);
        $this->assertSame($boss->id, $worker->reports_to_id);

        // HR-02: the boss cannot report to someone who reports to them.
        $this->patchJson("/api/v1/hr/employees/{$boss->id}", ['reports_to_id' => $worker->id], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'manager_cycle');

        // A user is linked to one employee only.
        $user = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $this->patchJson("/api/v1/hr/employees/{$boss->id}", ['user_id' => $user->id], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->patchJson("/api/v1/hr/employees/{$worker->id}", ['user_id' => $user->id], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'user_linked');
    }

    public function test_termination_ends_the_contract_and_rehire_brings_the_employee_back(): void
    {
        Event::fake([EmployeeTerminated::class, EmployeeHired::class, ContractChanged::class]);
        $employee = $this->employee();

        $this->postJson("/api/v1/hr/employees/{$employee->id}/contracts", [
            'type' => 'permanent', 'start_date' => '2026-01-05', 'pay_basis' => 'monthly', 'pay_amount' => '45000.00', 'currency' => 'KES', 'hours_per_week' => 45,
        ], $this->headersFor($this->hrOfficer))->assertCreated()->assertJsonPath('data.pay', ['amount_minor' => '4500000', 'currency' => 'KES']);

        // HR-07: one contract in force at a time.
        $this->postJson("/api/v1/hr/employees/{$employee->id}/contracts", [
            'type' => 'casual', 'start_date' => '2026-03-01', 'pay_basis' => 'daily', 'pay_amount' => '800', 'currency' => 'KES',
        ], $this->headersFor($this->hrOfficer))->assertUnprocessable()->assertJsonPath('code', 'contract_overlap');

        $this->postJson("/api/v1/hr/employees/{$employee->id}/terminate", ['termination_date' => '2026-06-30', 'reason' => 'resignation'], $this->headersFor($this->hrOfficer))
            ->assertOk()->assertJsonPath('data.status', 'terminated')->assertJsonPath('data.termination_date', '2026-06-30');
        $this->assertSame(['ended', '2026-06-30'], $this->inTenant(fn () => [Contract::query()->sole()->status, Contract::query()->sole()->end_date->toDateString()]));

        Event::assertDispatched(EmployeeTerminated::class, fn (EmployeeTerminated $e) => $e->employeeId === $employee->id && $e->reason === 'resignation');
        Event::assertDispatched(ContractChanged::class, fn (ContractChanged $e) => $e->change === 'ended' && $e->payMinor === '4500000');

        $this->postJson("/api/v1/hr/employees/{$employee->id}/terminate", ['termination_date' => '2026-07-01', 'reason' => 'other'], $this->headersFor($this->hrOfficer))
            ->assertStatus(409)->assertJsonPath('code', 'employee_terminated');
        $this->postJson("/api/v1/hr/employees/{$employee->id}/rehire", ['hire_date' => '2026-06-01'], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'rehire_before_termination');
        $this->postJson("/api/v1/hr/employees/{$employee->id}/rehire", ['hire_date' => '2026-09-01'], $this->headersFor($this->hrOfficer))
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.hire_date', '2026-09-01');
        Event::assertDispatched(EmployeeHired::class, fn (EmployeeHired $e) => $e->rehired && $e->hireDate === '2026-09-01');
    }

    public function test_a_branch_role_sees_its_branch_employees_only_and_cannot_hire_elsewhere(): void
    {
        $atA = $this->employee(['first_name' => 'Alpha']);
        $atB = $this->employee(['first_name' => 'Bravo', 'branch_id' => $this->branchB->id]);
        $atCompany = $this->employee(['first_name' => 'Charlie', 'branch_id' => null]);

        $names = array_column($this->getJson('/api/v1/hr/employees', $this->headersFor($this->managerA))->assertOk()->json('data'), 'first_name');
        $this->assertSame(['Alpha'], $names);
        $this->getJson("/api/v1/hr/employees/{$atB->id}", $this->headersFor($this->managerA))->assertNotFound();
        $this->getJson("/api/v1/hr/employees/{$atCompany->id}", $this->headersFor($this->managerA))->assertNotFound();
        $this->getJson("/api/v1/hr/employees/{$atA->id}", $this->headersFor($this->managerA))->assertOk();

        // The branch manager holds no hr.employee.create (the HR Officer hires).
        $this->hire([], $this->managerA)->assertForbidden();
        $this->patchJson("/api/v1/hr/employees/{$atA->id}", ['first_name' => 'Al'], $this->headersFor($this->managerA))->assertForbidden();

        // An HR Officer of branch B only cannot hire at branch A.
        $officerB = $this->userWith('hr_officer', Scope::branch($this->branchB->id));
        $this->hire([], $officerB)->assertForbidden();
        $this->hire(['branch_id' => $this->branchB->id], $officerB)->assertCreated();
    }

    public function test_the_module_must_be_active_and_other_tenants_ids_are_not_found(): void
    {
        $employee = $this->employee();
        $other = $this->otherTenant();

        // TEN-01: another tenant's owner cannot see or change it.
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('hr'));
        $this->getJson("/api/v1/hr/employees/{$employee->id}", $this->headersFor($other['user']))->assertNotFound();
        $this->patchJson("/api/v1/hr/employees/{$employee->id}", ['first_name' => 'X'], $this->headersFor($other['user']))->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/hr/employees', $this->headersFor($other['user']))->assertOk()->json('data'));
        $this->postJson('/api/v1/hr/employees', ['company_id' => $this->acme->id, 'first_name' => 'X', 'last_name' => 'Y', 'hire_date' => '2026-01-01'], $this->headersFor($other['user']))
            ->assertUnprocessable();

        // RBAC-08: switched off, every route answers module_inactive.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('hr'));
        $this->getJson('/api/v1/hr/employees', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
