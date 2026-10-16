<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Models\Employee;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-03, HR-04, HR-09, HR-10, HR-11, HR-31, RBAC-05, EXP-01: sensitive
// groups shown by permission and field rules in the API, lists, exports
// and audit; identifiers and account numbers encrypted at rest; reads of
// sensitive data audited; the employee's own view masked.
class SensitiveDataTest extends TestCase
{
    use BuildsHr, ReadsListExports, RefreshTenantDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
        $this->employee = $this->employee(['date_of_birth' => '1990-04-12', 'medical_notes' => 'Asthma: inhaler in the locker', 'personal_phone' => '+254733000999']);

        $headers = $this->headersFor($this->hrOfficer);
        $this->putJson("/api/v1/hr/employees/{$this->employee->id}/identifiers", ['identifiers' => [
            ['type' => 'national_id', 'value' => '12345678'], ['type' => 'kra_pin', 'value' => 'A012345678Z'],
        ]], $headers)->assertOk();
        $this->postJson("/api/v1/hr/employees/{$this->employee->id}/pay-accounts", [
            'kind' => 'mobile_money', 'provider' => 'M-Pesa', 'account_name' => 'Amina Otieno', 'account_number' => '254711000222', 'currency' => 'KES',
        ], $headers)->assertCreated()->assertJsonPath('data.is_primary', true);
        $this->postJson("/api/v1/hr/employees/{$this->employee->id}/contracts", [
            'type' => 'permanent', 'start_date' => '2026-01-05', 'pay_basis' => 'monthly', 'pay_amount' => '45000', 'currency' => 'KES',
        ], $headers)->assertCreated();
    }

    public function test_the_hr_officer_sees_every_group_and_each_read_is_audited(): void
    {
        $data = $this->getJson("/api/v1/hr/employees/{$this->employee->id}", $this->headersFor($this->hrOfficer))->assertOk()->json('data');

        $this->assertSame('1990-04-12', $data['date_of_birth']);
        $this->assertSame('Asthma: inhaler in the locker', $data['medical_notes']);
        $this->assertSame(['kra_pin' => 'A012345678Z', 'national_id' => '12345678'], array_column($data['identifiers'], 'value', 'type'));
        $this->assertSame('••••5678', $data['identifiers'][1]['masked']);
        $this->assertSame('254711000222', $data['pay_accounts'][0]['account_number']);
        $this->assertSame(['amount_minor' => '4500000', 'currency' => 'KES', 'basis' => 'monthly'], $data['pay']);
        $this->assertTrue($data['access']['bank']['edit']);

        // HR-11: one read audit naming the groups shown.
        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_view')->where('auditable_id', $this->employee->id)->latest('seq')->first());
        $this->assertSame(['bank', 'identity', 'medical', 'pay'], $entry->after['groups']);
    }

    public function test_a_branch_manager_sees_no_sensitive_group_and_nothing_is_audited(): void
    {
        $before = $this->sensitiveViews();
        $data = $this->getJson("/api/v1/hr/employees/{$this->employee->id}", $this->headersFor($this->managerA))->assertOk()->json('data');

        $this->assertArrayNotHasKey('date_of_birth', $data);
        $this->assertArrayNotHasKey('medical_notes', $data);
        $this->assertArrayNotHasKey('pay', $data);
        $this->assertSame([null, null], array_column($data['identifiers'], 'value'));
        $this->assertSame(['••••678Z', '••••5678'], array_column($data['identifiers'], 'masked'));
        $this->assertNull($data['pay_accounts'][0]['account_number']);
        $this->assertSame('••••0222', $data['pay_accounts'][0]['masked']);
        $this->assertSame($before, $this->sensitiveViews());

        // Writes and the full lists of a group need its permissions.
        $this->getJson("/api/v1/hr/employees/{$this->employee->id}/pay-accounts", $this->headersFor($this->managerA))->assertForbidden();
        $this->putJson("/api/v1/hr/employees/{$this->employee->id}/identifiers", ['identifiers' => [['type' => 'national_id', 'value' => '1']]], $this->headersFor($this->managerA))->assertForbidden();
        $this->getJson("/api/v1/hr/employees/{$this->employee->id}/contracts", $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.0.pay', null);
    }

    public function test_field_rules_hide_a_group_even_from_its_permission_holder_in_the_api_lists_and_exports(): void
    {
        // RBAC-05: the HR Officer role hides pay and identifiers and locks medical notes.
        $this->inTenant(function () {
            $role = $this->roles->get('hr_officer');
            FieldRule::create(['role_id' => $role->id, 'resource' => 'hr_employee', 'field' => 'pay', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'hr_employee', 'field' => 'identifiers', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'hr_employee', 'field' => 'medical_notes', 'mode' => 'readonly']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'hr_employee', 'field' => 'personal_phone', 'mode' => 'hidden']);
        });
        $headers = $this->headersFor($this->hrOfficer);

        $data = $this->getJson("/api/v1/hr/employees/{$this->employee->id}", $headers)->assertOk()->json('data');
        $this->assertArrayNotHasKey('pay', $data);
        $this->assertArrayNotHasKey('identifiers', $data);
        $this->assertArrayNotHasKey('personal_phone', $data);
        $this->assertSame('1990-04-12', $data['date_of_birth']);
        $this->assertSame(['view' => false, 'edit' => false], $data['access']['pay']);

        // The list, and its export, leave the columns out.
        $row = $this->getJson('/api/v1/hr/employees', $headers)->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('pay', $row);
        $this->assertArrayNotHasKey('personal_phone', $row);
        $this->assertSame('1990-04-12', $row['date_of_birth']);
        $csv = $this->get('/api/v1/hr/employees?format=csv', $headers)->assertOk()->streamedContent();
        $this->assertStringNotContainsString('45,000', $csv);
        $this->assertStringNotContainsString('254733000999', $csv);
        $this->assertStringContainsString('1990', $csv);
        $this->getJson('/api/v1/hr/employees?format=csv&columns[]=pay', $headers)->assertUnprocessable()->assertJsonPath('code', 'export_no_columns');

        // Writes: a locked or hidden field is refused.
        $this->patchJson("/api/v1/hr/employees/{$this->employee->id}", ['medical_notes' => 'x'], $headers)->assertUnprocessable()->assertJsonPath('code', 'field_readonly');
        $this->putJson("/api/v1/hr/employees/{$this->employee->id}/identifiers", ['identifiers' => [['type' => 'national_id', 'value' => '1']]], $headers)->assertForbidden();

        // The owner's role has no rules: still sees everything.
        $this->assertSame('4500000', $this->getJson('/api/v1/hr/employees', $this->headersFor())->json('data.0.pay.amount_minor'));
    }

    public function test_identifiers_account_numbers_and_medical_notes_are_encrypted_at_rest_and_never_in_the_audit_log(): void
    {
        $raw = $this->inTenant(fn () => [
            DB::table('hr_employee_identifiers')->where('type', 'national_id')->first(),
            DB::table('hr_pay_accounts')->first(),
            DB::table('hr_employees')->where('id', $this->employee->id)->value('medical_notes'),
        ]);

        $this->assertStringNotContainsString('12345678', $raw[0]->value);
        $this->assertSame('12345678', Crypt::decryptString($raw[0]->value));
        $this->assertSame('5678', $raw[0]->value_hint);
        $this->assertStringNotContainsString('12345678', $raw[0]->value_digest);
        $this->assertStringNotContainsString('254711000222', $raw[1]->account_number);
        $this->assertSame('254711000222', Crypt::decryptString($raw[1]->account_number));
        $this->assertStringNotContainsString('Asthma', $raw[2]);

        // HR-11: the audit log names what changed, never the values.
        $log = $this->inTenant(fn () => AuditEntry::query()->get()->map(fn ($e) => json_encode([$e->before, $e->after]))->implode("\n"));
        foreach (['12345678', 'A012345678Z', '254711000222', 'Asthma', '1990-04-12', '4500000'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_update')->whereJsonContains('after->fields', 'identifiers')->exists()));

        // HR-03: the same ID on a second employee of the company is refused (keyed digest).
        $other = $this->employee(['first_name' => 'Baraka']);
        $this->putJson("/api/v1/hr/employees/{$other->id}/identifiers", ['identifiers' => [['type' => 'national_id', 'value' => '1234 5678']]], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'identifier_taken');
        $this->putJson("/api/v1/hr/employees/{$other->id}/identifiers", ['identifiers' => [['type' => 'cnss_number', 'value' => '9']]], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'identifier_type_unknown');
    }

    public function test_the_employee_sees_their_own_record_masked(): void
    {
        $user = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $this->patchJson("/api/v1/hr/employees/{$this->employee->id}", ['user_id' => $user->id], $this->headersFor($this->hrOfficer))->assertOk();

        $me = $this->getJson('/api/v1/hr/me', $this->headersFor($user))->assertOk()->json('data');
        $this->assertSame([null, null], array_column($me['identifiers'], 'value'));
        $this->assertNull($me['pay_accounts'][0]['account_number']);
        $this->assertArrayNotHasKey('medical_notes', $me);
        $this->assertArrayNotHasKey('pay', $me);

        // Their own contacts are theirs to change; nothing else.
        $this->patchJson('/api/v1/hr/me', ['personal_phone' => '+254722333444', 'emergency_contacts' => [['name' => 'Juma', 'phone' => '+254700000009']]], $this->headersFor($user))
            ->assertOk()->assertJsonPath('data.personal_phone', '+254722333444');
        $this->patchJson('/api/v1/hr/me', ['first_name' => 'Changed'], $this->headersFor($user))->assertOk()->assertJsonPath('data.first_name', 'Amina');

        // Someone not linked to an employee has no self-service: GET hr/me says so with 204 (decision 30), the rest is not found.
        $this->getJson('/api/v1/hr/me', $this->headersFor($this->managerA))->assertNoContent();
        $this->getJson('/api/v1/hr/me/leave', $this->headersFor($this->managerA))->assertNotFound()->assertJsonPath('code', 'not_an_employee');
        $this->patchJson('/api/v1/hr/me', ['personal_phone' => '+254722333444'], $this->headersFor($this->managerA))->assertNotFound()->assertJsonPath('code', 'not_an_employee');
    }

    private function sensitiveViews(): int
    {
        return app(TenantContext::class)->run($this->owner->tenant_id, fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_view')->count());
    }
}
