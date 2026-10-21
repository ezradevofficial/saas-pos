<?php

namespace Modules\Payroll\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Payroll\Events\PayrollApproved;
use Modules\Payroll\Events\PayrollPosted;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Statutory\PayrollPack;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Modules\Payroll\Tests\Concerns\FixturePacks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PAY-08, PAY-09, PAY-14 to PAY-17, PAY-23, PAY-28: a run calculated from
// HR's contract and the fixture pack, refused while the pack lacks a
// value, approved by someone other than its preparer (maker-checker),
// posted and locked; permission-denied and other-tenant cases.
class RunsTest extends TestCase
{
    use BuildsPayroll, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPayroll();
    }

    public function test_a_run_is_calculated_from_the_contract_and_the_pack(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $id = $this->calculatedRun();

        $run = $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.status', 'calculated')
            ->assertJsonPath('data.period_start', '2026-10-01')
            ->assertJsonPath('data.period_end', '2026-10-31')
            ->assertJsonPath('data.pay_date', '2026-10-28')
            ->assertJsonPath('data.employees', 1)
            ->assertJsonPath('data.prepared_by.id', $this->officer->id)
            ->json('data');
        $this->assertSame('3810000', $run['totals']['currencies'][0]['net']['amount_minor']);

        $line = $this->lines($id)[$amina->number];
        $this->assertSame('5000000', $line['gross']['amount_minor']);
        $this->assertSame('1190000', $line['statutory']['amount_minor']);
        $this->assertSame('3810000', $line['net']['amount_minor']);
        $this->assertSame('155000', $line['employer']['amount_minor']);
        $this->assertSame('4800000', $line['taxable_pay']['amount_minor']);
        $byCode = collect($line['statutory_lines'])->keyBy('code');
        $this->assertSame('940000', $byCode['paye']['employee']['amount_minor']);
        $this->assertSame('100000', $byCode['nssf']['employer']['amount_minor']);
        $this->assertSame(['status' => 'new'], $line['variance']);

        // AUD-01: calculating is audited, without amounts.
        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'payroll.run.calculate')->where('auditable_id', $id)->sole());
        $this->assertStringNotContainsString('3810000', json_encode($entry->after));
    }

    public function test_a_run_is_refused_while_the_pack_misses_a_value(): void
    {
        $this->payrollEmployee();
        // A pack whose PAYE values are all null (as the packs shipped before the researched values).
        $pack = json_decode((string) file_get_contents(base_path('country-packs/KE/payroll.json')), true);
        $pack['components'][0]['periods'] = [['effective_from' => '2026-01-01', 'effective_to' => null, 'needs_confirmation' => true, 'bands' => null, 'rounding' => null]];
        app(PayrollPack::class)->fake('KE', $pack);
        $id = $this->createRun()->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))
            ->assertUnprocessable()->assertJsonPath('code', 'statutory_rates_missing');
        $this->assertStringContainsString('PAYE: needs confirmation, bands, rounding', $response->json('message'));
        $this->assertSame('paye', $response->json('missing.0.component'));

        // The run keeps the list; nothing was calculated.
        $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.missing.items.0.component', 'paye');
        $this->assertSame(0, $this->inTenant(fn () => RunLine::query()->where('run_id', $id)->count()));

        // In French too.
        $fr = $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], [...$this->headersFor($this->officer), 'Accept-Language' => 'fr'])->assertUnprocessable();
        $this->assertStringContainsString('valeurs légales manquent', $fr->json('message'));

        // One missing value is enough: the fixture without SHIF's minimum.
        app(PayrollPack::class)->fake('KE', FixturePacks::ke(['shif' => ['employee_rate' => '2', 'employee_min_minor' => null, 'reduces_taxable' => true, 'rounding' => ['mode' => 'half_up', 'unit_minor' => '1']]]));
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertUnprocessable()
            ->assertJsonPath('missing', [['component' => 'shif', 'fields' => ['employee_min_minor']]]);

        // A country without a pack.
        app(PayrollPack::class)->fake('KE', null);
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'payroll_pack_missing');
    }

    public function test_maker_checker_the_preparer_never_approves_and_posting_locks_the_run(): void
    {
        Event::fake([PayrollApproved::class, PayrollPosted::class]);
        $this->payrollEmployee();
        // The Owner prepares and submits: Owners approve by default, but never their own run (APR-07).
        $id = $this->createRun(by: $this->owner)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'pending');

        $this->approve($id, $this->owner)->assertForbidden();
        // The officer holds payroll.run.approve but is not the flow's approver: the approval is not theirs to see.
        $this->approve($id, $this->officer)->assertNotFound();
        // Not posted before approval.
        $this->postJson("/api/v1/payroll/runs/{$id}/post", [], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'run_status');

        $checker = $this->userWith('owner', Scope::tenant());
        $this->approve($id, $checker)->assertOk();
        $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertJsonPath('data.status', 'approved');
        Event::assertDispatched(PayrollApproved::class, fn (PayrollApproved $e) => $e->runId === $id);

        $this->postJson("/api/v1/payroll/runs/{$id}/post", [], $this->headersFor($this->officer))->assertOk()->assertJsonPath('data.status', 'posted');
        Event::assertDispatched(PayrollPosted::class, fn (PayrollPosted $e) => $e->runId === $id && $e->groups[0]['amounts']['net_pay'] === '3810000'
            && $e->groups[0]['amounts']['statutory.paye'] === '940000' && $e->groups[0]['amounts']['employer.nita'] === '5000'
            // Decision 41: a department of fewer than three employees posts at company level.
            && $e->groups[0]['department_id'] === null);
        $this->assertSame(1, $this->inTenant(fn () => Payslip::query()->where('run_id', $id)->count()));

        // Posted is final: no recalculation, no cancel, and the database refuses any change.
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'run_status');
        $this->postJson("/api/v1/payroll/runs/{$id}/cancel", [], $this->headersFor($this->officer))->assertUnprocessable();
        $this->expectExceptionMessage('locked');
        $this->inTenant(fn () => RunLine::query()->where('run_id', $id)->update(['net_minor' => 1]));
    }

    public function test_a_rejected_run_goes_back_to_calculated_and_one_regular_run_per_period(): void
    {
        $this->payrollEmployee();
        $id = $this->calculatedRun();
        $this->createRun()->assertUnprocessable()->assertJsonPath('code', 'run_exists');
        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headersFor($this->officer))->assertOk();

        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $id)->where('status', 'pending')->firstOrFail());
        $this->postJson("/api/v1/approvals/{$approval->id}/reject", ['comment' => 'Check overtime'], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($this->officer))->assertJsonPath('data.status', 'calculated');

        // Cancelled: the period is free again.
        $this->postJson("/api/v1/payroll/runs/{$id}/cancel", ['reason' => 'Wrong group'], $this->headersFor($this->officer))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->createRun()->assertCreated();
    }

    public function test_a_line_with_an_error_blocks_submission(): void
    {
        $amina = $this->payrollEmployee('5000000');
        // A deduction larger than the pay: negative net.
        $item = $this->postJson('/api/v1/payroll/items', ['company_id' => $this->acme->id, 'code' => 'UNION', 'name' => 'Union dues', 'kind' => 'deduction'], $this->headersFor($this->officer))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/employees/{$amina->id}/items", ['item_id' => $item, 'amount_minor' => '9000000', 'currency' => 'KES', 'recurrence' => 'once', 'period_date' => '2026-10-10'], $this->headersFor($this->officer))->assertCreated();
        $id = $this->calculatedRun();

        $this->assertSame('net_negative', $this->lines($id)[$amina->number]['errors'][0]['code']);
        $this->postJson("/api/v1/payroll/runs/{$id}/submit", [], $this->headersFor($this->officer))->assertUnprocessable()->assertJsonPath('code', 'run_has_errors');
    }

    public function test_permissions_scope_and_other_tenants(): void
    {
        $this->payrollEmployee();
        $id = $this->calculatedRun();

        // A Branch Manager holds no payroll permission: the run is not found, the list refused.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($manager))->assertNotFound();
        $this->getJson('/api/v1/payroll/runs', $this->headersFor($manager))->assertForbidden();
        $this->createRun(by: $manager)->assertForbidden();

        // An Approver reads the run (to approve it) but can't prepare or post.
        $approver = $this->userWith('approver', Scope::company($this->acme->id));
        $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($approver))->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($approver))->assertForbidden();

        // A Read-only Auditor reads no pay.
        $auditor = $this->userWith('read_only_auditor', Scope::tenant());
        $this->getJson('/api/v1/payroll/runs', $this->headersFor($auditor))->assertForbidden();
        $this->getJson("/api/v1/payroll/runs/{$id}/lines", $this->headersFor($auditor))->assertNotFound();

        // Another tenant's owner sees nothing of it.
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('payroll'));
        $this->getJson("/api/v1/payroll/runs/{$id}", $this->headersFor($other['user']))->assertNotFound();
        $this->postJson("/api/v1/payroll/runs/{$id}/calculate", [], $this->headersFor($other['user']))->assertNotFound();
        $this->postJson('/api/v1/payroll/runs', ['pay_group_id' => $this->monthly->id], $this->headersFor($other['user']))->assertUnprocessable();
    }

    public function test_payroll_routes_are_closed_without_the_module(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('payroll'));
        $this->getJson('/api/v1/payroll/runs', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
