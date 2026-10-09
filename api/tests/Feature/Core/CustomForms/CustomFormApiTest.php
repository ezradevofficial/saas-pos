<?php

namespace Tests\Feature\Core\CustomForms;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * CF-04, CF-05, NUM-01, WF-01, WF-10, APR-04, RBAC-04, TEN-01: custom form
 * types, their fields (run-time custom field entities), records with lines,
 * totals and numbers, validation, a workflow approval moving the status,
 * place scoping, the role allow-list, and other tenants.
 */
class CustomFormApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private User $admin;

    private User $managerA;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('media');
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(TenantCurrencies::class)->activate('KES'));
        $this->admin = $this->userWith('admin', Scope::tenant());
        $this->managerA = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
    }

    /** "Petty cash request": amount and reason, lines with a description and an amount. */
    private function pettyCash(array $settings = []): array
    {
        $type = $this->postJson('/api/v1/custom-form-types', [
            'key' => 'petty_cash', 'name' => 'Petty cash request', 'workflow' => true, 'has_lines' => true, 'attachments' => true, ...$settings,
        ], $this->headersFor())->assertCreated()->json('data');

        foreach ([
            ['entity' => 'custom_form:petty_cash', 'key' => 'reason', 'label' => 'Reason', 'type' => 'text', 'required' => true],
            ['entity' => 'custom_form:petty_cash', 'key' => 'needed_on', 'label' => 'Needed on', 'type' => 'date'],
            ['entity' => 'custom_form_line:petty_cash', 'key' => 'description', 'label' => 'Description', 'type' => 'text', 'required' => true],
            ['entity' => 'custom_form_line:petty_cash', 'key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ['entity' => 'custom_form_line:petty_cash', 'key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
        ] as $field) {
            $this->postJson('/api/v1/custom-fields', $field, $this->headersFor())->assertCreated();
        }

        return $type;
    }

    private function lines(): array
    {
        return [
            ['custom' => ['description' => 'Fuel', 'amount' => ['amount_minor' => '250000', 'currency' => 'KES'], 'quantity' => '1.5']],
            ['custom' => ['description' => 'Water', 'amount' => ['amount_minor' => '30050', 'currency' => 'KES'], 'quantity' => '2']],
        ];
    }

    private function create(string $typeId, array $body = [], ?User $as = null)
    {
        return $this->postJson("/api/v1/custom-form-types/{$typeId}/records", [
            'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id,
            'custom' => ['reason' => 'Site visit'], 'lines' => $this->lines(), ...$body,
        ], $this->headersFor($as));
    }

    public function test_admins_build_form_types_whose_fields_are_custom_field_entities(): void
    {
        $type = $this->pettyCash();
        $this->assertSame(['custom_form:petty_cash', 'core.custom_form_petty_cash', 'core.custom_form_petty_cash'], [$type['entity'], $type['document_type'], $type['number_type']]);

        $entities = array_column($this->getJson('/api/v1/custom-fields/meta', $this->headersFor())->assertOk()->json('data.entities'), 'label', 'key');
        $this->assertSame('Petty cash request', $entities['custom_form:petty_cash']);
        $this->assertArrayHasKey('custom_form_line:petty_cash', $entities);

        // The key is set once and never reused; the name changes.
        $this->postJson('/api/v1/custom-form-types', ['key' => 'petty_cash', 'name' => 'Again'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->patchJson("/api/v1/custom-form-types/{$type['id']}", ['key' => 'other'], $this->headersFor())->assertUnprocessable();
        $this->patchJson("/api/v1/custom-form-types/{$type['id']}", ['name' => 'Petty cash', 'line_fields' => ['amount', 'description']], $this->headersFor())
            ->assertOk()->assertJsonPath('data.name', 'Petty cash')->assertJsonPath('data.line_fields', ['amount', 'description']);
        $this->patchJson("/api/v1/custom-form-types/{$type['id']}", ['line_fields' => ['nothing']], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('line_fields.0');

        // A line field can't be unique (lines are parts of a record).
        $this->postJson('/api/v1/custom-fields', ['entity' => 'custom_form_line:petty_cash', 'key' => 'code', 'label' => 'Code', 'type' => 'text', 'unique' => true], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('unique');

        // Only managers build types; archiving keeps it (TEN-06).
        $this->postJson('/api/v1/custom-form-types', ['key' => 'vehicle', 'name' => 'Vehicle request'], $this->headersFor($this->managerA))->assertForbidden();
        $this->postJson("/api/v1/custom-form-types/{$type['id']}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', fn ($at) => $at !== null);
        $this->assertSame([], array_column($this->getJson('/api/v1/custom-form-types', $this->headersFor($this->managerA))->assertOk()->json('data'), 'key'));
        $this->postJson("/api/v1/custom-form-types/{$type['id']}/restore", [], $this->headersFor())->assertOk();
        $this->assertSame(['petty_cash'], array_column($this->getJson('/api/v1/custom-form-types', $this->headersFor($this->managerA))->json('data'), 'key'));
        $this->assertTrue(AuditEntry::query()->where('action', 'core.custom_form_type.create')->exists());

        // LAY-03: the form has a layout of its own: the place and header fields, the lines, the attachments.
        $layout = $this->getJson('/api/v1/config/form_layout/resolved?key=custom_form.petty_cash', $this->headersFor($this->managerA))->assertOk()->json('data.payload.sections');
        $this->assertSame(['main', 'lines', 'attachments'], array_column($layout, 'id'));
        $this->assertSame(['place', 'custom.needed_on', 'custom.reason'], array_column($layout[0]['fields'], 'id'));

        // NUM-01 and WF-01: the type is a numbered and a workflow document type.
        $this->assertContains('core.custom_form_petty_cash', array_column($this->getJson('/api/v1/workflow/document-types', $this->headersFor())->assertOk()->json('data'), 'key'));
    }

    public function test_a_record_is_numbered_with_its_fields_lines_totals_and_attachments(): void
    {
        $type = $this->pettyCash();
        $file = $this->post("/api/v1/custom-form-types/{$type['id']}/attachments", ['file' => UploadedFile::fake()->create('receipt.pdf', 4, 'application/pdf')], [...$this->headersFor($this->managerA), 'Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $record = $this->create($type['id'], ['attachments' => [$file]], $this->managerA)->assertCreated()
            ->assertJsonPath('data.number', 'PETTY_CASH-'.now()->format('Y').'-00001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.custom.reason', 'Site visit')
            ->assertJsonPath('data.totals.amount', ['amount_minor' => '280050', 'currency' => 'KES'])
            ->assertJsonPath('data.totals.quantity', '3.5')
            ->assertJsonPath('data.amount', ['amount_minor' => '280050', 'currency' => 'KES'])
            ->assertJsonPath('data.lines.1.custom.description', 'Water')
            ->assertJsonPath('data.attachments.0.name', 'receipt.pdf')
            ->assertJsonPath('data.can.edit', true)
            ->json('data');

        // A draft changes; its lines are replaced and the old ones stay in the audit.
        $this->patchJson("/api/v1/custom-form-records/{$record['id']}", ['lines' => [$this->lines()[0]]], $this->headersFor($this->managerA))
            ->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.totals.amount.amount_minor', '250000');
        $this->assertTrue(AuditEntry::query()->where('action', 'core.custom_form.lines')->exists());

        // The second record takes the next number.
        $this->create($type['id'], [], $this->managerA)->assertCreated()->assertJsonPath('data.number', 'PETTY_CASH-'.now()->format('Y').'-00002');

        $list = $this->getJson("/api/v1/custom-form-types/{$type['id']}/records?search=00001&sort=number", $this->headersFor($this->managerA))->assertOk();
        $this->assertSame([$record['id']], array_column($list->json('data'), 'id'));
        $this->getJson("/api/v1/custom-form-types/{$type['id']}/records?format=csv", $this->headersFor($this->managerA))->assertOk();
    }

    public function test_records_are_validated(): void
    {
        $type = $this->pettyCash();

        $this->create($type['id'], ['custom' => ['needed_on' => '2026-10-12']])->assertUnprocessable()->assertJsonValidationErrors('custom.reason');
        $this->create($type['id'], ['lines' => [['custom' => ['amount' => ['amount_minor' => '100', 'currency' => 'KES']]]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.custom.description');
        $this->inTenant(fn () => app(TenantCurrencies::class)->activate('USD'));
        $this->create($type['id'], ['lines' => [
            ['custom' => ['description' => 'A', 'amount' => ['amount_minor' => '100', 'currency' => 'KES']]],
            ['custom' => ['description' => 'B', 'amount' => ['amount_minor' => '100', 'currency' => 'USD']]],
        ]])->assertUnprocessable()->assertJsonValidationErrors('lines.1.custom.amount');
        $this->create($type['id'], ['branch_id' => null, 'location_id' => $this->locationB->id])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->create($type['id'], ['attachments' => ['01900000-0000-7000-8000-000000000000']])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        $plain = $this->postJson('/api/v1/custom-form-types', ['key' => 'note', 'name' => 'Note'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/custom-form-types/{$plain}/records", ['company_id' => $this->acme->id, 'lines' => [['custom' => []]]], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('lines');
        // Without a workflow, submitting closes the record.
        $this->postJson("/api/v1/custom-form-types/{$plain}/records", ['company_id' => $this->acme->id, 'submit' => true], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.number', 'NOTE-'.now()->format('Y').'-00001');
    }

    public function test_a_submitted_record_waits_in_its_flow_and_an_approval_moves_its_status(): void
    {
        $type = $this->pettyCash();
        $record = $this->create($type['id'], ['submit' => true], $this->managerA)->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('meta.workflow.current.0.node_id', 'approve')
            ->json('data');

        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_type', 'core.custom_form_petty_cash')->where('document_id', $record['id'])->sole());
        // APR-04: the inbox shows the number, the form's name and the amount.
        $item = collect($this->getJson('/api/v1/approvals', $this->headersFor($this->admin))->assertOk()->json('data'))->firstWhere('id', $approval->id);
        $this->assertSame([$record['number'], 'Petty cash request'], [$item['document']['number'] ?? $item['number'] ?? null, $item['document']['title'] ?? $item['title'] ?? null]);

        // A pending record no longer changes.
        $this->patchJson("/api/v1/custom-form-records/{$record['id']}", ['custom' => ['reason' => 'Changed']], $this->headersFor($this->managerA))->assertUnprocessable();

        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->admin))->assertOk();
        $this->getJson("/api/v1/custom-form-records/{$record['id']}", $this->headersFor($this->managerA))->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('meta.workflow.outcome', 'approved');
    }

    public function test_records_are_scoped_by_place_and_the_types_roles(): void
    {
        $type = $this->pettyCash();
        $atA = $this->create($type['id'], [], $this->managerA)->assertCreated()->json('data.id');
        $atB = $this->create($type['id'], ['branch_id' => $this->branchB->id])->assertCreated()->json('data.id');

        // RBAC-04: the branch A manager sees A's record, not B's, and can't create at B.
        $this->assertSame([$atA], array_column($this->getJson("/api/v1/custom-form-types/{$type['id']}/records", $this->headersFor($this->managerA))->json('data'), 'id'));
        $this->getJson("/api/v1/custom-form-records/{$atB}", $this->headersFor($this->managerA))->assertForbidden();
        $this->create($type['id'], ['branch_id' => $this->branchB->id], $this->managerA)->assertForbidden();
        $this->assertCount(2, $this->getJson("/api/v1/custom-form-types/{$type['id']}/records", $this->headersFor())->json('data'));

        // Without the permissions: refused.
        $this->getJson("/api/v1/custom-form-types/{$type['id']}/records", $this->headersFor($this->cashier))->assertForbidden();
        $this->create($type['id'], ['branch_id' => $this->branchA->id], $this->cashier)->assertForbidden();

        // The type limited to Accountants: the branch manager loses it; the Owner keeps it.
        $this->patchJson("/api/v1/custom-form-types/{$type['id']}", ['role_ids' => [$this->roles->get('accountant')->id]], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/custom-form-types/{$type['id']}/records", $this->headersFor($this->managerA))->assertForbidden();
        $this->getJson("/api/v1/custom-form-records/{$atA}", $this->headersFor($this->managerA))->assertForbidden();
        $this->assertCount(2, $this->getJson("/api/v1/custom-form-types/{$type['id']}/records", $this->headersFor())->json('data'));

        // Cancel and archive (never deleted).
        $this->postJson("/api/v1/custom-form-records/{$atB}/cancel", ['reason' => 'Not needed'], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/custom-form-records/{$atB}/archive", [], $this->headersFor())->assertOk();
        $this->assertSame(2, $this->inTenant(fn () => CustomFormRecord::query()->count()));
    }

    public function test_another_tenant_sees_nothing(): void
    {
        $type = $this->pettyCash();
        $record = $this->create($type['id'])->assertCreated()->json('data.id');
        $other = $this->otherTenant();
        $headers = $this->headersFor($other['user']);

        $this->getJson("/api/v1/custom-form-types/{$type['id']}", $headers)->assertNotFound();
        $this->getJson("/api/v1/custom-form-types/{$type['id']}/records", $headers)->assertNotFound();
        $this->getJson("/api/v1/custom-form-records/{$record}", $headers)->assertNotFound();
        $this->patchJson("/api/v1/custom-form-records/{$record}", ['custom' => ['reason' => 'x']], $headers)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/custom-form-types', $headers)->assertOk()->json('data'));
        $this->assertNotContains('custom_form:petty_cash', array_column($this->getJson('/api/v1/custom-fields/meta', $headers)->json('data.entities'), 'key'));
        // The other tenant may use the same key for its own form.
        $this->postJson('/api/v1/custom-form-types', ['key' => 'petty_cash', 'name' => 'Petty cash'], $headers)->assertCreated();
    }
}
