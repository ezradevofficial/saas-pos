<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\RoleTemplates;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\HR\Contracts\Identifiers;
use Modules\HR\Contracts\PayAccounts;
use Modules\HR\Models\Employee;
use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Models\LeaveType;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Review fixes (HR-03, HR-19, HR-27, HR-30, HR-33, HR-38, AUTH-12, MD-07,
// owner decision B): identifier saves, branch scope on rosters and swaps,
// the appraisal reviewer, linking users, record history, downloads
// audited when they happen, Payroll's reads, and Payroll without HR.
class ReviewAccessTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
    }

    public function test_saving_one_identifier_keeps_the_others_and_only_remove_deletes(): void
    {
        $employee = $this->employee();
        $url = "/api/v1/hr/employees/{$employee->id}/identifiers";
        $this->putJson($url, ['identifiers' => [['type' => 'national_id', 'value' => '12345678'], ['type' => 'kra_pin', 'value' => 'A012345678Z']]], $this->headersFor($this->hrOfficer))->assertOk();

        // The web sends only what changed; an empty box keeps the value.
        $this->putJson($url, ['identifiers' => [['type' => 'kra_pin', 'value' => 'A099999999Z'], ['type' => 'national_id', 'value' => '']]], $this->headersFor($this->hrOfficer))->assertOk();
        $this->assertSame(['kra_pin' => 'A099999999Z', 'national_id' => '12345678'], $this->identifiers($employee));

        $this->putJson($url, ['identifiers' => [['type' => 'national_id', 'remove' => true]]], $this->headersFor($this->hrOfficer))->assertOk();
        $this->assertSame(['kra_pin' => 'A099999999Z'], $this->identifiers($employee));
    }

    public function test_roster_members_and_swap_peers_from_another_branch_need_the_roster_permission_there(): void
    {
        $atA = $this->employee(['first_name' => 'Alpha']);
        $atB = $this->employee(['first_name' => 'Bravo', 'branch_id' => $this->branchB->id]);
        $atA2 = $this->employee(['first_name' => 'Alpha two']);
        $shift = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'D', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:00'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $rotation = $this->postJson('/api/v1/hr/rotations', ['company_id' => $this->acme->id, 'name' => 'Every day', 'days' => [$shift]], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');

        // Branch A's manager rosters branch A's people, not branch B's.
        $roster = $this->postJson('/api/v1/hr/rosters', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'A', 'rotation_id' => $rotation, 'anchor_date' => '2026-11-02',
            'members' => [['employee_id' => $atA->id], ['employee_id' => $atB->id]]], $this->headersFor($this->managerA))->assertForbidden();
        $roster = $this->postJson('/api/v1/hr/rosters', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'A', 'rotation_id' => $rotation, 'anchor_date' => '2026-11-02',
            'members' => [['employee_id' => $atA->id]]], $this->headersFor($this->managerA))->assertCreated()->json('data.id');
        $this->putJson("/api/v1/hr/rosters/{$roster}/members", ['members' => [['employee_id' => $atB->id]]], $this->headersFor($this->managerA))->assertForbidden();
        // Moving it to branch B needs the permission there.
        $this->patchJson("/api/v1/hr/rosters/{$roster}", ['branch_id' => $this->branchB->id], $this->headersFor($this->managerA))->assertForbidden();
        $this->patchJson("/api/v1/hr/rosters/{$roster}", ['branch_id' => $this->branchB->id], $this->headersFor($this->hrOfficer))->assertOk();

        // A swap with a peer of another branch is refused; within the branch it belongs where the shift is.
        $mine = $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $atA->id, 'date' => '2026-11-20', 'shift_id' => $shift], $this->headersFor($this->managerA))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/shift-swaps', ['employee_id' => $atA->id, 'roster_shift_id' => $mine, 'peer_employee_id' => $atB->id], $this->headersFor($this->managerA))->assertForbidden();
        $swap = $this->postJson('/api/v1/hr/shift-swaps', ['employee_id' => $atA->id, 'roster_shift_id' => $mine, 'peer_employee_id' => $atA2->id], $this->headersFor($this->managerA))->assertCreated()->json('data.id');

        // The peer moves to branch B: accepting for them is checked at their place.
        $this->patchJson("/api/v1/hr/employees/{$atA2->id}", ['branch_id' => $this->branchB->id], $this->headersFor($this->hrOfficer))->assertOk();
        $this->postJson("/api/v1/hr/shift-swaps/{$swap}/accept", [], $this->headersFor($this->managerA))->assertForbidden();
    }

    public function test_only_hr_chooses_the_reviewer_and_never_the_employee_themselves(): void
    {
        $bossUser = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $boss = $this->employee(['first_name' => 'Boss']);
        $this->inTenant(fn () => Employee::query()->whereKey($boss->id)->update(['user_id' => $bossUser->id]));
        $workerUser = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $worker = $this->employee(['first_name' => 'Worker', 'reports_to_id' => $boss->id]);
        $this->inTenant(fn () => Employee::query()->whereKey($worker->id)->update(['user_id' => $workerUser->id]));
        $cycle = $this->postJson('/api/v1/hr/appraisal-cycles', ['company_id' => $this->acme->id, 'name' => '2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/hr/appraisal-cycles/{$cycle}/open", [], $this->headersFor($this->hrOfficer))->assertOk();
        $appraisal = $this->getJson("/api/v1/hr/appraisals?employee={$worker->id}", $this->headersFor($this->hrOfficer))->json('data.0.id');

        // The reviewer cannot hand the review to the employee (or anyone).
        $this->patchJson("/api/v1/hr/appraisals/{$appraisal}", ['reviewer_id' => $workerUser->id], $this->headersFor($bossUser))->assertForbidden();
        $this->patchJson("/api/v1/hr/appraisals/{$appraisal}", ['reviewer_id' => $workerUser->id], $this->headersFor($this->hrOfficer))->assertUnprocessable()->assertJsonPath('code', 'reviewer_is_employee');
        $this->patchJson("/api/v1/hr/appraisals/{$appraisal}", ['reviewer_id' => $this->managerA->id], $this->headersFor($this->hrOfficer))->assertOk();
    }

    public function test_linking_a_user_needs_its_own_permission_and_nobody_links_themselves(): void
    {
        $employee = $this->employee();
        $user = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        // An HR clerk: the HR Officer's permissions without hr.employee.link.
        $editor = $this->userWith('hr_officer', Scope::company($this->acme->id));
        $this->inTenant(function () use ($editor) {
            $role = Role::query()->where('template_key', 'hr_officer')->sole();
            $copy = app(RoleTemplates::class)->copy($role, 'HR clerk');
            $copy->setPermissions($copy->permissions()->where('name', '!=', 'hr.employee.link')->get());
            RoleAssignment::query()->where('user_id', $editor->id)->update(['role_id' => $copy->id]);
        });

        $this->patchJson("/api/v1/hr/employees/{$employee->id}", ['user_id' => $user->id], $this->headersFor($editor))->assertForbidden();
        $this->patchJson("/api/v1/hr/employees/{$employee->id}", ['user_id' => $this->hrOfficer->id], $this->headersFor($this->hrOfficer))->assertUnprocessable()->assertJsonPath('code', 'link_self');
        $this->patchJson("/api/v1/hr/employees/{$employee->id}", ['user_id' => $user->id], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_record_history_hides_field_rules_fields_and_disciplinary_cases_from_others(): void
    {
        $employee = $this->employee(['personal_phone' => '+254711000999']);
        $case = $this->postJson('/api/v1/hr/disciplinary-cases', ['employee_id' => $employee->id, 'category' => 'final_warning', 'incident_date' => '2026-10-01', 'description' => 'Late'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data.id');

        $this->assertNotEmpty($this->getJson("/api/v1/history/hr_disciplinary_case/{$case}", $this->headersFor($this->hrOfficer))->assertOk()->json('data'));
        $this->getJson("/api/v1/history/hr_disciplinary_case/{$case}", $this->headersFor($this->managerA))->assertNotFound();

        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'resource' => 'hr_employee', 'field' => 'personal_phone', 'mode' => 'hidden']));
        $history = json_encode($this->getJson("/api/v1/history/hr_employee/{$employee->id}", $this->headersFor($this->managerA))->assertOk()->json('data'));
        $this->assertStringNotContainsString('254711000999', $history);
        $this->assertStringContainsString('254711000999', json_encode($this->getJson("/api/v1/history/hr_employee/{$employee->id}", $this->headersFor($this->hrOfficer))->json('data')));
    }

    public function test_a_document_is_audited_when_downloaded_not_when_its_link_is_issued(): void
    {
        Storage::fake('media');
        $employee = $this->employee();
        $document = $this->post("/api/v1/hr/employees/{$employee->id}/documents", ['category' => 'certificate', 'title' => 'Diploma', 'file' => UploadedFile::fake()->create('d.pdf', 4, 'application/pdf')], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data.id');

        $url = $this->getJson("/api/v1/hr/documents/{$document}/link", $this->headersFor($this->hrOfficer))->assertOk()->json('data.url');
        $this->assertSame(0, $this->downloads($document));
        $this->get($url)->assertOk();
        $this->assertSame(1, $this->downloads($document));
        $this->assertSame($this->hrOfficer->id, $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.document.download')->value('user_id')));
    }

    public function test_payroll_reads_pay_accounts_and_identifiers_with_the_permission_or_inside_a_run_and_each_read_is_audited(): void
    {
        $employee = $this->employee();
        $this->putJson("/api/v1/hr/employees/{$employee->id}/identifiers", ['identifiers' => [['type' => 'kra_pin', 'value' => 'A012345678Z']]], $this->headersFor($this->hrOfficer))->assertOk();
        $this->postJson("/api/v1/hr/employees/{$employee->id}/pay-accounts", ['kind' => 'bank', 'provider' => 'Bank', 'account_name' => 'A', 'account_number' => '00112233', 'currency' => 'KES'], $this->headersFor($this->hrOfficer))->assertCreated();
        $payroll = $this->userWith('payroll_officer', Scope::company($this->acme->id));

        $accounts = $this->inTenant(fn () => app(PayAccounts::class)->forUser($employee->id, $payroll));
        $this->assertSame('00112233', $accounts[0]['account_number']);
        $this->assertSame(['kra_pin' => 'A012345678Z'], $this->inTenant(fn () => app(Identifiers::class)->forPayrollRun($employee->id, 'run-1')));

        try {
            $this->inTenant(fn () => app(PayAccounts::class)->forUser($employee->id, $this->managerA));
            $this->fail('A branch manager read pay accounts.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $reads = $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_read')->orderBy('seq')->pluck('after')->all());
        $this->assertEquals([['groups' => ['bank'], 'context' => 'user'], ['groups' => ['identity'], 'context' => 'payroll_run', 'run_id' => 'run-1']], $reads);
    }

    public function test_payroll_alone_opens_the_employee_records_but_not_the_rest_of_hr(): void
    {
        $this->inTenant(function () {
            app(ModuleRegistry::class)->register('payroll');
            app(ModuleRegistry::class)->activate('payroll');
            app(ModuleRegistry::class)->deactivate('hr');
        });

        $employee = $this->hire()->assertCreated()->json('data.id');
        $this->getJson('/api/v1/hr/employees', $this->headersFor($this->hrOfficer))->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/hr/employees/{$employee}/contracts", ['type' => 'permanent', 'start_date' => '2026-01-05', 'pay_basis' => 'monthly', 'pay_amount' => '45000', 'currency' => 'KES'], $this->headersFor($this->hrOfficer))->assertCreated();
        $this->postJson("/api/v1/hr/employees/{$employee}/pay-accounts", ['kind' => 'bank', 'provider' => 'Bank', 'account_name' => 'A', 'account_number' => '00112233', 'currency' => 'KES'], $this->headersFor($this->hrOfficer))->assertCreated();

        // Leave, rosters and attendance stay closed, and no statutory leave types were copied.
        $this->getJson('/api/v1/hr/leave-types', $this->headersFor($this->hrOfficer))->assertForbidden()->assertJsonPath('code', 'module_inactive');
        $this->getJson('/api/v1/hr/timesheets', $this->headersFor($this->hrOfficer))->assertForbidden();
        $this->assertSame(0, $this->inTenant(fn () => LeaveType::query()->count()));

        // Nothing at all without either.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('payroll'));
        $this->getJson('/api/v1/hr/employees', $this->headersFor($this->hrOfficer))->assertForbidden();
    }

    /** @return array<string, string> */
    private function identifiers(Employee $employee): array
    {
        return $this->inTenant(fn () => EmployeeIdentifier::query()->where('employee_id', $employee->id)->orderBy('type')->get()->mapWithKeys(fn ($i) => [$i->type => $i->value])->all());
    }

    private function downloads(string $document): int
    {
        return $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.document.download')->where('auditable_id', $document)->count());
    }
}
