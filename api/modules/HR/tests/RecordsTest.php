<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\HR\Models\Employee;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-27 to HR-30, HR-09, HR-11: documents by category with audited signed
// downloads; onboarding checklists started on hire; disciplinary cases
// only for their permission holders; appraisals reviewed by the line
// manager and acknowledged by the employee.
class RecordsTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    private Employee $amina;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('media');
        $this->setUpHr();
        $this->amina = $this->employee();
    }

    public function test_documents_are_seen_by_category_and_every_download_link_is_audited(): void
    {
        $upload = fn (string $category) => $this->post("/api/v1/hr/employees/{$this->amina->id}/documents", [
            'category' => $category, 'title' => ucfirst($category), 'file' => UploadedFile::fake()->create("{$category}.pdf", 20, 'application/pdf'),
        ], $this->headersFor($this->hrOfficer));

        $contract = $upload('contract')->assertCreated()->json('data.id');
        $upload('certificate')->assertCreated();
        $upload('medical')->assertCreated();

        // HR Officer: all three. Branch manager (no hr.document.view, no sensitive group): none.
        $this->assertCount(3, $this->getJson("/api/v1/hr/employees/{$this->amina->id}/documents", $this->headersFor($this->hrOfficer))->assertOk()->json('data'));
        $this->getJson("/api/v1/hr/employees/{$this->amina->id}/documents", $this->headersFor($this->managerA))->assertForbidden();
        $this->getJson("/api/v1/hr/documents/{$contract}/link", $this->headersFor($this->managerA))->assertNotFound();

        // An Admin reads general documents only (no pay group: no contract scans).
        $admin = $this->userWith('admin', Scope::tenant());
        $this->assertSame(['certificate'], array_column($this->getJson("/api/v1/hr/employees/{$this->amina->id}/documents", $this->headersFor($admin))->assertOk()->json('data'), 'category'));

        // The link is signed for its reader, re-checked on download, and audited.
        $url = $this->getJson("/api/v1/hr/documents/{$contract}/link", $this->headersFor($this->hrOfficer))->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.document.download')->where('auditable_id', $contract)->exists()));

        // Field rules hiding pay from the HR Officer role hide contract scans too, even through a link issued before.
        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->roles->get('hr_officer')->id, 'resource' => 'hr_employee', 'field' => 'pay', 'mode' => 'hidden']));
        $this->get($url)->assertNotFound();
        $this->getJson("/api/v1/hr/documents/{$contract}/link", $this->headersFor($this->hrOfficer))->assertNotFound();

        // Files of the wrong type are refused.
        $this->post("/api/v1/hr/employees/{$this->amina->id}/documents", ['category' => 'other', 'title' => 'Script', 'file' => UploadedFile::fake()->create('x.exe', 1, 'application/x-msdownload')], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'file_type');
    }

    public function test_the_default_onboarding_checklist_starts_on_hire_and_completes_item_by_item(): void
    {
        $this->postJson('/api/v1/hr/checklist-templates', [
            'company_id' => $this->acme->id, 'kind' => 'onboarding', 'name' => 'New starter', 'is_default' => true,
            'items' => [['title' => 'Sign contract', 'due_days' => 0], ['title' => 'Issue uniform', 'due_days' => 3]],
        ], $this->headersFor($this->hrOfficer))->assertCreated();

        $baraka = $this->employee(['first_name' => 'Baraka', 'hire_date' => '2026-11-02']);
        $checklist = $this->getJson("/api/v1/hr/checklists?employee={$baraka->id}", $this->headersFor($this->hrOfficer))->assertOk()->json('data.0');
        $this->assertSame(['open', ['2026-11-02', '2026-11-05']], [$checklist['status'], array_column($checklist['items'], 'due_on')]);

        $this->postJson("/api/v1/hr/checklist-items/{$checklist['items'][0]['id']}/complete", ['note' => 'Signed'], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.status', 'open');
        $this->postJson("/api/v1/hr/checklist-items/{$checklist['items'][1]['id']}/complete", [], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/v1/hr/checklist-items/{$checklist['items'][1]['id']}/reopen", [], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.status', 'open');
        $this->postJson("/api/v1/hr/checklist-items/{$checklist['items'][1]['id']}/complete", [], $this->headersFor($this->managerA))->assertForbidden();
    }

    public function test_disciplinary_cases_are_only_for_their_holders_and_their_texts_never_reach_the_audit_log(): void
    {
        $case = $this->postJson('/api/v1/hr/disciplinary-cases', [
            'employee_id' => $this->amina->id, 'category' => 'written_warning', 'incident_date' => '2026-10-01', 'description' => 'Till short by KES 2,000 twice',
        ], $this->headersFor($this->hrOfficer))->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^DC-2026-00001$/', $case['number']);

        $this->getJson("/api/v1/hr/disciplinary-cases/{$case['id']}", $this->headersFor($this->managerA))->assertNotFound();
        $this->getJson('/api/v1/hr/disciplinary-cases', $this->headersFor($this->managerA))->assertForbidden();
        $auditor = $this->userWith('read_only_auditor', Scope::tenant());
        $this->getJson("/api/v1/hr/disciplinary-cases/{$case['id']}", $this->headersFor($auditor))->assertNotFound();

        $this->postJson("/api/v1/hr/disciplinary-cases/{$case['id']}/close", ['outcome' => 'Warning on file for 6 months'], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.status', 'closed');
        $this->patchJson("/api/v1/hr/disciplinary-cases/{$case['id']}", ['description' => 'x'], $this->headersFor($this->hrOfficer))->assertStatus(409);

        $log = $this->inTenant(fn () => AuditEntry::query()->where('module', 'hr')->get()->map(fn ($e) => json_encode([$e->before, $e->after]))->implode("\n"));
        $this->assertStringNotContainsString('Till short', $log);
        $this->assertStringNotContainsString('Warning on file', $log);

        // Field rules hide the group from the HR Officer role too.
        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->roles->get('hr_officer')->id, 'resource' => 'hr_employee', 'field' => 'disciplinary', 'mode' => 'hidden']));
        $this->getJson('/api/v1/hr/disciplinary-cases', $this->headersFor($this->hrOfficer))->assertForbidden();
        $this->getJson("/api/v1/hr/disciplinary-cases/{$case['id']}", $this->headersFor($this->hrOfficer))->assertNotFound();
    }

    public function test_an_appraisal_cycle_reviewed_by_the_line_manager_and_acknowledged_by_the_employee(): void
    {
        $bossUser = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $boss = $this->employee(['first_name' => 'Boss', 'user_id' => $bossUser->id]);
        $workerUser = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $this->patchJson("/api/v1/hr/employees/{$this->amina->id}", ['reports_to_id' => $boss->id, 'user_id' => $workerUser->id], $this->headersFor($this->hrOfficer))->assertOk();

        $cycle = $this->postJson('/api/v1/hr/appraisal-cycles', ['company_id' => $this->acme->id, 'name' => '2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->postJson("/api/v1/hr/appraisal-cycles/{$cycle}/open", [], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.status', 'open');

        $mine = $this->getJson('/api/v1/hr/me/appraisals', $this->headersFor($workerUser))->assertOk()->json('data.0');
        $this->assertSame($bossUser->id, $mine['reviewer']['id']);
        $this->patchJson("/api/v1/hr/me/appraisals/{$mine['id']}", ['self_rating' => 4, 'self_comments' => 'Good year', 'submit' => true], $this->headersFor($workerUser))
            ->assertOk()->assertJsonPath('data.status', 'manager_review');

        // The reviewer (no HR role) writes the review: weighted goals 3 × 2 and 5 × 1 → 3.67.
        $this->patchJson("/api/v1/hr/appraisals/{$mine['id']}", ['goals' => [['title' => 'Sales', 'weight' => 2, 'rating' => 3], ['title' => 'Service', 'weight' => 1, 'rating' => 5]], 'manager_comments' => 'Solid'], $this->headersFor($bossUser))
            ->assertOk()->assertJsonPath('data.overall_rating', '3.67');
        $this->postJson("/api/v1/hr/appraisals/{$mine['id']}/complete", [], $this->headersFor($bossUser))->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/v1/hr/me/appraisals/{$mine['id']}/acknowledge", [], $this->headersFor($workerUser))->assertOk()->assertJsonPath('data.status', 'acknowledged');

        // Someone else's appraisal is not theirs to see.
        $this->getJson("/api/v1/hr/appraisals/{$mine['id']}", $this->headersFor($workerUser))->assertNotFound();
    }
}
