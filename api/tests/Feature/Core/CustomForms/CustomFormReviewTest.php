<?php

namespace Tests\Feature\Core\CustomForms;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomForms\CustomFormAttachment;
use App\Core\CustomForms\CustomFormLine;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\Identity\Models\User;
use App\Core\Numbering\NumberFormat;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * Review fixes for custom forms (CF-04, CF-05, RBAC-04, RBAC-05, AUD-01):
 * the type's role list on flows and approvals, hidden money in amount and
 * totals, lines kept by id and archived, attachments removed, restore,
 * archive and cancel rules, archived types, submit checks, the form layout
 * catalogue, totals.
 */
class CustomFormReviewTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private User $admin;

    private User $managerA;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('media');
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(TenantCurrencies::class)->activate('KES'));
        $this->admin = $this->userWith('admin', Scope::tenant());
        $this->managerA = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
    }

    private function pettyCash(array $settings = [], array $extraFields = []): array
    {
        $type = $this->postJson('/api/v1/custom-form-types', [
            'key' => 'petty_cash', 'name' => 'Petty cash request', 'workflow' => true, 'has_lines' => true, 'attachments' => true, ...$settings,
        ], $this->headersFor())->assertCreated()->json('data');

        foreach ([
            ['entity' => 'custom_form:petty_cash', 'key' => 'reason', 'label' => 'Reason', 'type' => 'text', 'required' => true],
            ['entity' => 'custom_form_line:petty_cash', 'key' => 'description', 'label' => 'Description', 'type' => 'text', 'required' => true],
            ['entity' => 'custom_form_line:petty_cash', 'key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ['entity' => 'custom_form_line:petty_cash', 'key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
            ...$extraFields,
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

    /** Finding 1: the type's role list holds on the flow, approval and inbox endpoints (RBAC-04). */
    public function test_the_types_role_list_holds_on_workflow_and_approval_endpoints(): void
    {
        $type = $this->pettyCash();
        $record = $this->create($type['id'], ['submit' => true], $this->managerA)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $record['id'])->sole());
        $flow = "/api/v1/document-workflows/core.custom_form_petty_cash/{$record['id']}";

        // Before the list: the Admin (an approver) sees the flow and the request.
        $this->getJson($flow, $this->headersFor($this->admin))->assertOk();
        $this->assertContains($approval->id, array_column($this->getJson('/api/v1/approvals', $this->headersFor($this->admin))->json('data'), 'id'));

        // The type limited to Accountants: the Admin keeps the generic permissions but loses the record.
        $this->patchJson("/api/v1/custom-form-types/{$type['id']}", ['role_ids' => [$this->roles->get('accountant')->id]], $this->headersFor())->assertOk();
        $this->getJson($flow, $this->headersFor($this->admin))->assertNotFound();
        $this->postJson("{$flow}/cancel", ['reason' => 'No'], $this->headersFor($this->admin))->assertNotFound();
        $this->postJson("{$flow}/return", ['node' => 'approve', 'reason' => 'No'], $this->headersFor($this->admin))->assertNotFound();
        $this->postJson("{$flow}/move", [], $this->headersFor($this->admin))->assertNotFound();
        $this->getJson("/api/v1/approvals/{$approval->id}", $this->headersFor($this->admin))->assertNotFound();
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->admin))->assertNotFound();
        $this->assertNotContains($approval->id, array_column($this->getJson('/api/v1/approvals', $this->headersFor($this->admin))->json('data'), 'id'));
        $this->postJson('/api/v1/approvals/bulk-approve', ['ids' => [$approval->id]], $this->headersFor($this->admin))->assertOk()->assertJsonPath('data.approved', []);
        $this->assertSame(ApprovalRequest::PENDING, $this->inTenant(fn () => $approval->fresh()->status));

        // The Owner passes the list.
        $this->getJson($flow, $this->headersFor())->assertOk();
    }

    /** Finding 2: money hidden by role doesn't leak through totals, amount, lists, exports or the inbox (RBAC-05). */
    public function test_hidden_money_stays_out_of_totals_amount_lists_and_the_inbox(): void
    {
        $type = $this->pettyCash();
        $accountant = $this->roles->get('accountant')->id;
        $field = $this->inTenant(fn () => CustomFieldDefinition::query()->where('entity', 'custom_form_line:petty_cash')->where('key', 'amount')->sole());
        $this->patchJson("/api/v1/custom-fields/{$field->id}", ['visible_roles' => [$accountant]], $this->headersFor())->assertOk();

        $record = $this->create($type['id'], ['submit' => true])->assertCreated()
            ->assertJsonPath('data.amount.amount_minor', '280050')->json('data');

        // The branch manager can't see the line amount: no total, no amount, no column or sort.
        $this->getJson("/api/v1/custom-form-records/{$record['id']}", $this->headersFor($this->managerA))->assertOk()
            ->assertJsonPath('data.amount', null)
            ->assertJsonMissingPath('data.totals.amount')
            ->assertJsonPath('data.totals.quantity', '3.5');
        $row = $this->getJson("/api/v1/custom-form-types/{$type['id']}/records", $this->headersFor($this->managerA))->assertOk()->json('data.0');
        $this->assertNull($row['amount']);
        $this->assertArrayNotHasKey('amount', (array) $row['totals']);
        $this->getJson("/api/v1/custom-form-types/{$type['id']}/records?sort=amount", $this->headersFor($this->managerA))->assertUnprocessable();
        $csv = $this->get("/api/v1/custom-form-types/{$type['id']}/records?format=csv", $this->headersFor($this->managerA))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('2,800.50', $csv);
        $this->assertStringNotContainsString('2800.50', $csv);

        // The approver (Admin) can't see it either: the inbox leaves the amount out.
        $item = collect($this->getJson('/api/v1/approvals', $this->headersFor($this->admin))->assertOk()->json('data'))->first();
        $this->assertNull($item['document']['amount'] ?? $item['amount'] ?? null);
        $this->assertStringNotContainsString('280050', json_encode($item));

        // The Owner sees everything.
        $this->getJson("/api/v1/custom-form-records/{$record['id']}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.totals.amount.amount_minor', '280050');
    }

    /** Findings 3 and 4: lines keep their ids, values the saver can't see stay, removed lines are archived, every save is audited. */
    public function test_lines_are_updated_in_place_archived_when_removed_and_audited(): void
    {
        $accountant = $this->roles->get('accountant')->id;
        $type = $this->pettyCash([], [
            ['entity' => 'custom_form_line:petty_cash', 'key' => 'code', 'label' => 'Cost code', 'type' => 'text', 'visible_roles' => [$accountant]],
        ]);
        $lines = $this->lines();
        $lines[0]['custom']['code'] = 'C-1';
        $lines[1]['custom']['code'] = 'C-2';
        $record = $this->create($type['id'], ['lines' => $lines])->assertCreated()->json('data');
        $ids = array_column($record['lines'], 'id');

        // AUD-01: the lines are audited on create too.
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.custom_form.lines')->where('auditable_id', $record['id'])->exists()));

        // The branch manager can't see the cost code; they change the first line and drop the second.
        $this->patchJson("/api/v1/custom-form-records/{$record['id']}", ['lines' => [
            ['id' => $ids[0], 'custom' => ['description' => 'Diesel']],
            ['custom' => ['description' => 'Snacks', 'quantity' => '1']],
        ]], $this->headersFor($this->managerA))->assertOk()
            ->assertJsonPath('data.lines.0.id', $ids[0])
            ->assertJsonPath('data.lines.0.custom.description', 'Diesel')
            ->assertJsonCount(2, 'data.lines');

        $stored = $this->inTenant(fn () => CustomFormLine::query()->where('record_id', $record['id'])->get()->keyBy('id'));
        $this->assertSame('C-1', $stored[$ids[0]]->custom['code']);
        $this->assertSame('250000', (string) $stored[$ids[0]]->custom['amount']['amount_minor']);
        $this->assertNotNull($stored[$ids[1]]->archived_at);
        $this->assertCount(3, $stored);

        // A line of another record (or an archived one) is refused.
        $this->patchJson("/api/v1/custom-form-records/{$record['id']}", ['lines' => [['id' => $ids[1], 'custom' => ['description' => 'Back']]]], $this->headersFor($this->managerA))
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.id');
    }

    /** Finding 5: attachments left out of a draft's list are archived, both ways audited. */
    public function test_attachments_are_removed_from_a_draft_by_archiving_them(): void
    {
        $type = $this->pettyCash();
        $upload = fn (string $name) => $this->post("/api/v1/custom-form-types/{$type['id']}/attachments", ['file' => UploadedFile::fake()->create($name, 4, 'application/pdf')], [...$this->headersFor($this->managerA), 'Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        [$a, $b] = [$upload('a.pdf'), $upload('b.pdf')];
        $record = $this->create($type['id'], ['attachments' => [$a, $b]], $this->managerA)->assertCreated()->assertJsonCount(2, 'data.attachments')->json('data.id');

        $this->patchJson("/api/v1/custom-form-records/{$record}", ['attachments' => [$a]], $this->headersFor($this->managerA))->assertOk()
            ->assertJsonCount(1, 'data.attachments')->assertJsonPath('data.attachments.0.id', $a);
        // Without the key, the list stays.
        $this->patchJson("/api/v1/custom-form-records/{$record}", ['custom' => ['reason' => 'Again']], $this->headersFor($this->managerA))->assertOk()->assertJsonCount(1, 'data.attachments');

        $this->assertNotNull($this->inTenant(fn () => CustomFormAttachment::query()->find($b)?->archived_at));
        $this->assertSame(2, $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.custom_form.attach')->count()));
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.custom_form.detach')->count()));
    }

    /** Finding 6: a restore re-checks unique header values. */
    public function test_a_restore_is_refused_while_another_record_holds_its_unique_value(): void
    {
        $type = $this->pettyCash([], [['entity' => 'custom_form:petty_cash', 'key' => 'ref', 'label' => 'Reference', 'type' => 'text', 'unique' => true]]);
        $first = $this->create($type['id'], ['custom' => ['reason' => 'A', 'ref' => 'R1']])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/custom-form-records/{$first}/archive", [], $this->headersFor())->assertOk();
        $this->create($type['id'], ['custom' => ['reason' => 'B', 'ref' => 'R1']])->assertCreated();

        $this->postJson("/api/v1/custom-form-records/{$first}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('custom.ref');
        $this->assertNotNull($this->inTenant(fn () => CustomFormRecord::query()->find($first)->archived_at));
    }

    /** Finding 7: a pending record isn't archived (cancel it first); an archived one isn't cancelled. */
    public function test_pending_records_are_not_archived_and_archived_ones_not_cancelled(): void
    {
        $type = $this->pettyCash();
        $pending = $this->create($type['id'], ['submit' => true])->assertCreated()->json('data');
        $this->assertFalse($pending['can']['archive']);
        $this->postJson("/api/v1/custom-form-records/{$pending['id']}/archive", [], $this->headersFor())
            ->assertStatus(409)->assertJsonPath('message', __('core.custom_form.errors.archive_pending'));

        $draft = $this->create($type['id'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/custom-form-records/{$draft}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/custom-form-records/{$draft}/cancel", ['reason' => 'x'], $this->headersFor())->assertStatus(409);
        $this->assertSame('draft', $this->inTenant(fn () => CustomFormRecord::query()->find($draft)->status));
    }

    /** Finding 8: an archived type's drafts are read only; its records stay readable in the list and on their page. */
    public function test_drafts_of_an_archived_type_are_read_only(): void
    {
        $type = $this->pettyCash();
        $draft = $this->create($type['id'], [], $this->managerA)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/custom-form-types/{$type['id']}/archive", [], $this->headersFor())->assertOk();

        $this->patchJson("/api/v1/custom-form-records/{$draft}", ['custom' => ['reason' => 'Changed']], $this->headersFor($this->managerA))->assertStatus(409);
        $this->postJson("/api/v1/custom-form-records/{$draft}/submit", [], $this->headersFor($this->managerA))->assertStatus(409);
        $this->getJson("/api/v1/custom-form-records/{$draft}", $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.can.edit', false);
        $this->assertSame([$draft], array_column($this->getJson("/api/v1/custom-form-types/{$type['id']}/records", $this->headersFor($this->managerA))->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v1/custom-form-types/{$type['id']}", $this->headersFor($this->managerA))->assertOk();
    }

    /** Finding 9: submit checks the stored record. */
    public function test_submit_checks_required_values_lines_and_the_amount_the_flow_reads(): void
    {
        $type = $this->pettyCash();
        $draft = $this->create($type['id'], ['lines' => []])->assertCreated()->json('data.id');
        // A required field added after the draft was saved.
        $this->postJson('/api/v1/custom-fields', ['entity' => 'custom_form:petty_cash', 'key' => 'purpose', 'label' => 'Purpose', 'type' => 'text', 'required' => true], $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/custom-fields', ['entity' => 'custom_form_line:petty_cash', 'key' => 'account', 'label' => 'Account', 'type' => 'text', 'required' => true], $this->headersFor())->assertCreated();

        $this->postJson("/api/v1/custom-form-records/{$draft}/submit", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors(['custom.purpose', 'lines']);

        $this->patchJson("/api/v1/custom-form-records/{$draft}", ['custom' => ['purpose' => 'Trip'], 'lines' => [['custom' => ['description' => 'Fuel', 'account' => 'A1']]]], $this->headersFor())->assertOk();
        $other = $this->create($type['id'], ['custom' => ['reason' => 'x', 'purpose' => 'y'], 'lines' => [['custom' => ['description' => 'Fuel', 'account' => 'A1']]]])->assertCreated()->json('data.id');
        $line = $this->inTenant(fn () => CustomFormLine::query()->where('record_id', $other)->sole());
        $this->inTenant(fn () => $line->forceFill(['custom' => ['description' => 'Fuel']])->save());
        $this->postJson("/api/v1/custom-form-records/{$other}/submit", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('lines.0.custom.account');

        // A flow reading the amount: a record without one is refused.
        $flow = $this->postJson('/api/v1/workflows', ['document_type' => 'core.custom_form_petty_cash', 'company_id' => $this->acme->id], $this->headersFor())->assertCreated()->json('data.id');
        $this->putJson("/api/v1/workflows/{$flow}/draft", ['graph' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'big', 'type' => 'condition', 'name' => 'Large?', 'condition' => ['field' => 'amount', 'op' => 'gt', 'value' => ['amount_minor' => '100000', 'currency' => 'KES']]],
                ['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'approval' => ['approver' => ['type' => 'role', 'role' => 'template:admin'], 'mode' => 'any']],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved'],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'big'],
                ['from' => 'big', 'to' => 'approve', 'branch' => 'yes'],
                ['from' => 'big', 'to' => 'approved', 'branch' => 'no'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ]], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/workflows/{$flow}/publish", [], $this->headersFor())->assertOk();

        $this->postJson("/api/v1/custom-form-records/{$draft}/submit", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->patchJson("/api/v1/custom-form-records/{$draft}", ['lines' => [['custom' => ['description' => 'Fuel', 'account' => 'A1', 'amount' => ['amount_minor' => '500', 'currency' => 'KES']]]]], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/custom-form-records/{$draft}/submit", [], $this->headersFor())->assertOk();
    }

    /** Finding 12: a total without values is null; a money total beyond the bigint range is a field error. */
    public function test_totals_are_null_without_values_and_bounded(): void
    {
        $type = $this->pettyCash();
        $this->create($type['id'], ['lines' => [['custom' => ['description' => 'Fuel']]]])->assertCreated()
            ->assertJsonPath('data.totals.quantity', null)->assertJsonPath('data.totals.amount', null)->assertJsonPath('data.amount', null);

        // Each value fits (18 digits); ten of them don't fit a bigint.
        $huge = ['custom' => ['description' => 'A', 'amount' => ['amount_minor' => '999999999999999999', 'currency' => 'KES']]];
        $this->create($type['id'], ['lines' => array_fill(0, 10, $huge)])
            ->assertUnprocessable()->assertJsonValidationErrors('totals.amount');
    }

    /** Finding 10: one form's fields need core.layout.edit; custom forms and hidden fields follow the viewer. */
    public function test_the_form_layout_catalogue_follows_the_viewer(): void
    {
        $type = $this->pettyCash([], [['entity' => 'custom_form:petty_cash', 'key' => 'budget', 'label' => 'Budget', 'type' => 'money', 'visible_roles' => [$this->roles->get('accountant')->id]]]);

        // A layout viewer sees the list but doesn't design forms.
        $viewer = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Layout viewer', ['core.layout.view', 'core.custom_form.view']), Scope::tenant());

            return $user;
        });
        $this->getJson('/api/v1/form-layouts', $this->headersFor($viewer))->assertOk();
        $this->getJson('/api/v1/form-layouts?form=custom_form.petty_cash', $this->headersFor($viewer))->assertForbidden();

        // The Admin designs them, without the field hidden from them.
        $form = $this->getJson('/api/v1/form-layouts?form=custom_form.petty_cash', $this->headersFor($this->admin))->assertOk()->json('data');
        $this->assertNotContains('custom.budget', array_column($form['fields'], 'id'));
        $this->assertNotContains('custom.budget', array_merge(...array_map(fn ($s) => array_column($s['fields'], 'id'), $form['defaults']['sections'])));
        $this->assertContains('custom.budget', array_column($this->getJson('/api/v1/form-layouts?form=custom_form.petty_cash', $this->headersFor())->json('data.fields'), 'id'));

        // A type the Admin may not use isn't in their catalogue.
        $this->patchJson("/api/v1/custom-form-types/{$type['id']}", ['role_ids' => [$this->roles->get('accountant')->id]], $this->headersFor())->assertOk();
        $this->assertNotContains('custom_form.petty_cash', array_column($this->getJson('/api/v1/form-layouts', $this->headersFor($this->admin))->json('data'), 'key'));
        $this->getJson('/api/v1/form-layouts?form=custom_form.petty_cash', $this->headersFor($this->admin))->assertUnprocessable();
        $this->assertContains('custom_form.petty_cash', array_column($this->getJson('/api/v1/form-layouts', $this->headersFor())->json('data'), 'key'));
    }

    /** Finding 11: a field made required (without a default) after a layout hid it shows again. */
    public function test_a_field_required_after_a_layout_hid_it_shows_again(): void
    {
        $this->pettyCash([], [['entity' => 'custom_form:petty_cash', 'key' => 'note', 'label' => 'Note', 'type' => 'text']]);
        $saved = $this->postJson('/api/v1/config/form_layout', ['key' => 'custom_form.petty_cash', 'scope_type' => 'tenant', 'scope_id' => null, 'payload' => ['tabs' => [], 'sections' => [
            ['id' => 'main', 'title' => 'Details', 'columns' => 2, 'fields' => [['id' => 'place'], ['id' => 'custom.reason'], ['id' => 'custom.note', 'hidden' => true]]],
        ]]], $this->headersFor());
        $this->assertContains($saved->status(), [200, 201], (string) $saved->getContent());
        $this->postJson("/api/v1/config/form_layout/{$saved->json('data.id')}/publish", ['revision' => $saved->json('data.draft.revision')], $this->headersFor())->assertOk();

        $note = fn () => collect($this->getJson('/api/v1/config/form_layout/resolved?key=custom_form.petty_cash', $this->headersFor($this->managerA))->assertOk()->json('data.payload.sections.0.fields'))->firstWhere('id', 'custom.note');
        $this->assertTrue($note()['hidden']);

        $field = $this->inTenant(fn () => CustomFieldDefinition::query()->where('entity', 'custom_form:petty_cash')->where('key', 'note')->sole());
        $this->patchJson("/api/v1/custom-fields/{$field->id}", ['required' => true], $this->headersFor())->assertOk();
        $this->assertFalse($note()['hidden'] ?? false);
    }

    /** Finding 13: a new form type's default number prefix can't be another document type's. */
    public function test_a_form_key_whose_number_prefix_is_taken_is_refused(): void
    {
        $this->inTenant(fn () => NumberFormat::query()->create(['document_type' => 'pos.receipt', 'pattern' => 'PO-{BRANCH}-{00001}', 'reset' => 'never']));

        $this->postJson('/api/v1/custom-form-types', ['key' => 'po', 'name' => 'Purchase order'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->postJson('/api/v1/custom-form-types', ['key' => 'pos', 'name' => 'Point of sale'], $this->headersFor())->assertCreated();
    }
}
