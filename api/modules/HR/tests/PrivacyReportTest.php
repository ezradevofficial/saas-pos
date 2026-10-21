<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08, HR-09, HR-11, RBAC-05 (review H2): an employee's access report
 * respects HR's sensitive groups. A downloader without a group's view
 * permission (or whose field rules hide it) gets it masked; a group shown
 * in full is audited as hr.employee.sensitive_view. Review H1: rows the
 * employee wrote about others (appraisals they reviewed) never appear.
 */
class PrivacyReportTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    private string $employeeId;

    private string $otherId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHr();
        $this->employeeId = $this->employee(['date_of_birth' => '1990-04-12'])->id;
        $this->otherId = $this->employee(['first_name' => 'Juma', 'last_name' => 'Kariuki'])->id;

        $this->inTenant(function () {
            DB::table('hr_employee_identifiers')->insert([
                'id' => (string) Str::uuid7(), 'employee_id' => $this->employeeId, 'company_id' => $this->acme->id,
                'type' => 'national_id', 'value' => 'encrypted-placeholder', 'value_hint' => '5678', 'value_digest' => str_repeat('d', 64),
            ]);
            DB::table('hr_disciplinary_cases')->insert([
                'id' => (string) Str::uuid7(), 'employee_id' => $this->employeeId, 'company_id' => $this->acme->id, 'number' => 'DC-1',
                'category' => 'written_warning', 'status' => 'open', 'incident_date' => '2026-09-01', 'description' => 'Late cash-up three times', 'opened_by' => $this->owner->id,
            ]);
        });
    }

    /** An access request about the employee, approved and carried out, downloaded by $by. */
    private function report($by): array
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'employee', 'subject_id' => $this->employeeId, 'kind' => 'access'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk();

        return $this->getJson("/api/v1/privacy/requests/{$id}/report", $this->headersFor($by))->assertOk()->json();
    }

    public function test_groups_the_downloader_may_not_see_are_masked(): void
    {
        // The Admin template holds privacy permissions but none of HR's sensitive groups (HR-09).
        $admin = $this->userWith('admin', Scope::tenant());
        $report = $this->report($admin);
        $json = json_encode($report);

        $this->assertSame('[hidden]', $report['records']['hr_employees'][0]['date_of_birth']);
        $this->assertSame('[hidden]', $report['related']['hr_employee_identifiers']['rows'][0]['value_hint']);
        $this->assertSame('disciplinary', $report['related']['hr_disciplinary_cases']['rows'][0]['hidden']);
        $this->assertStringNotContainsString('Late cash-up', $json);
        $this->assertStringNotContainsString('1990-04-12', $json);
        $this->assertStringNotContainsString('5678', $json);
        // Encrypted values never enter a report at all.
        $this->assertStringNotContainsString('encrypted-placeholder', $json);

        $this->assertSame([], $this->reportViews());
    }

    public function test_groups_shown_in_full_are_audited_as_a_sensitive_view(): void
    {
        $report = $this->report($this->owner);

        $this->assertSame('1990-04-12', $report['records']['hr_employees'][0]['date_of_birth']);
        $this->assertSame('5678', $report['related']['hr_employee_identifiers']['rows'][0]['value_hint']);
        $this->assertSame('Late cash-up three times', $report['related']['hr_disciplinary_cases']['rows'][0]['description']);

        // Identity (date of birth, ID hint) and disciplinary: the groups the report showed.
        $this->assertSame(['disciplinary', 'identity'], $this->reportViews());
    }

    /** @return list<string> groups audited as shown in full by an access report */
    private function reportViews(): array
    {
        return $this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.employee.sensitive_view')->where('auditable_id', $this->employeeId)->get()
            ->filter(fn (AuditEntry $entry) => ($entry->after['context'] ?? null) === 'privacy_access_report')
            ->flatMap(fn (AuditEntry $entry) => $entry->after['groups'])->unique()->sort()->values()->all());
    }

    public function test_appraisals_a_user_reviewed_for_others_never_appear_in_their_report(): void
    {
        $this->inTenant(function () {
            $cycle = (string) Str::uuid7();
            DB::table('hr_appraisal_cycles')->insert(['id' => $cycle, 'company_id' => $this->acme->id, 'name' => 'Q3', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30', 'status' => 'open']);
            DB::table('hr_appraisals')->insert([
                'id' => (string) Str::uuid7(), 'employee_id' => $this->otherId, 'reviewer_id' => $this->managerA->id, 'company_id' => $this->acme->id,
                ...$this->appraisalColumns(), 'cycle_id' => $cycle,
            ]);
        });

        $admin = $this->userWith('admin', Scope::tenant());
        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'user', 'subject_id' => $this->managerA->id, 'kind' => 'access'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk();
        $report = $this->getJson("/api/v1/privacy/requests/{$id}/report", $this->headersFor())->assertOk()->json();

        $this->assertArrayNotHasKey('hr_appraisals', $report['related']);
        $this->assertStringNotContainsString($this->otherId, json_encode($report));
    }

    /** @return array<string, mixed> the other required columns of an appraisal */
    private function appraisalColumns(): array
    {
        $required = collect(DB::select("select column_name, data_type from information_schema.columns where table_name = 'hr_appraisals' and is_nullable = 'NO' and column_default is null"))
            ->reject(fn ($c) => in_array($c->column_name, ['id', 'employee_id', 'reviewer_id', 'company_id', 'tenant_id'], true));

        return $required->mapWithKeys(fn ($c) => [$c->column_name => match (true) {
            $c->column_name === 'status' => 'self_review',
            $c->column_name === 'goals' => '[]',
            str_contains($c->data_type, 'date') => '2026-09-30',
            str_contains($c->data_type, 'int') => 1,
            $c->data_type === 'jsonb' => '[]',
            $c->data_type === 'uuid' => $this->owner->id,
            default => 'Q3 review',
        }])->all();
    }
}
