<?php

namespace Modules\Stores\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Notification;
use Modules\Stores\Tests\Concerns\BuildsStores;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// STR-13, STR-14, TEN-01, RBAC-04, RBAC-05: every Stores endpoint refuses
// people without the permission, readers out of the store's scope and
// another tenant's ids; the delivery note and issue voucher print.
class AccessAndPrintsTest extends TestCase
{
    use BuildsStores, RefreshTenantDatabase;

    /** @var array<string, mixed> */
    private array $issue;

    /** @var array<string, mixed> */
    private array $requisition;

    /** @var array<string, mixed> */
    private array $return;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpStores();
        $this->opening($this->soap, '20');
        $this->requisition = $this->approved([['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'qty' => '1']]);
        $this->issue = $this->issue($this->requisition['id'], [[$this->requisition['lines'][0]['id'], '1']])->assertCreated()->json('data');
        $this->return = $this->postJson("/api/v1/stores/issues/{$this->issue['id']}/returns", ['reason' => 'unused', 'lines' => [['issue_line_id' => $this->issue['lines'][0]['id'], 'qty' => '1']]], $this->headersFor())
            ->assertCreated()->json('data');
    }

    public function test_the_delivery_note_and_issue_voucher_print_as_html_and_pdf(): void
    {
        $note = $this->get("/api/v1/stores/issues/{$this->issue['id']}/print", $this->headersFor($this->storekeeper))->assertOk()->getContent();
        $this->assertStringContainsString('Soap', $note);
        $this->assertStringContainsString('Kitchen', $note);
        $this->assertStringContainsString($this->requisition['number'], $note);
        $this->assertStringContainsString($this->issue['number'], $note);

        $voucher = $this->get("/api/v1/stores/issues/{$this->issue['id']}/print?document=issue_voucher", $this->headersFor())->assertOk()->getContent();
        $this->assertStringContainsString('Issue voucher', $voucher);
        $pdf = $this->get("/api/v1/stores/issues/{$this->issue['id']}/print?document=issue_voucher&format=pdf", $this->headersFor())->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Only the two documents, as HTML or PDF; both are real (live) template types once the module is active.
        $this->get("/api/v1/stores/issues/{$this->issue['id']}/print?document=delivery_note&format=csv", $this->headersFor())->assertUnprocessable();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $types = array_column($this->getJson('/api/v1/templates/types?scope_type=company&scope_id='.$this->acme->id, $this->headersFor())->assertOk()->json('data'), 'live', 'key');
        $this->assertSame([true, true], [$types['stores.delivery_note'], $types['stores.issue_voucher']]);
    }

    public function test_every_endpoint_needs_its_permission(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->store->id));
        $headers = $this->headersFor($cashier);

        foreach (['stores/status', 'stores/requisitions', 'stores/issues', 'stores/returns', 'stores/ledger', 'stores/stock', 'stores/consumption'] as $list) {
            $this->getJson("/api/v1/{$list}", $headers)->assertForbidden();
        }

        $this->getJson("/api/v1/stores/requisitions/{$this->requisition['id']}", $headers)->assertNotFound();
        $this->getJson("/api/v1/stores/issues/{$this->issue['id']}", $headers)->assertNotFound();
        $this->get("/api/v1/stores/issues/{$this->issue['id']}/print", $headers)->assertNotFound();
        $this->getJson("/api/v1/stores/returns/{$this->return['id']}", $headers)->assertNotFound();
        $this->postJson('/api/v1/stores/stock-entries', ['location_id' => $this->store->id, 'kind' => 'opening', 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']]], $headers)->assertForbidden();

        // An approver sees the requisition but may neither issue nor return.
        $approver = $this->headersFor($this->userWith('approver', Scope::location($this->store->id)));
        $this->getJson("/api/v1/stores/requisitions/{$this->requisition['id']}", $approver)->assertOk();
        $this->postJson("/api/v1/stores/requisitions/{$this->requisition['id']}/issues", ['lines' => [['requisition_line_id' => $this->requisition['lines'][0]['id'], 'qty' => '1']]], $approver)->assertForbidden();
        $this->postJson("/api/v1/stores/requisitions/{$this->requisition['id']}/close", ['reason' => 'x'], $approver)->assertForbidden();
        $this->postJson("/api/v1/stores/requisitions/{$this->requisition['id']}/cancel", ['reason' => 'x'], $approver)->assertForbidden();
        $this->getJson("/api/v1/stores/issues/{$this->issue['id']}", $approver)->assertNotFound();

        // A procurement officer reads requisitions, the ledger and consumption, never values.
        $buyer = $this->headersFor($this->userWith('procurement_officer', Scope::location($this->store->id)));
        $this->getJson('/api/v1/stores/ledger', $buyer)->assertOk()->assertJsonPath('data.0.value', null);
        $this->getJson('/api/v1/stores/consumption', $buyer)->assertOk();
        $this->getJson('/api/v1/stores/issues', $buyer)->assertForbidden();
        $this->postJson("/api/v1/stores/issues/{$this->issue['id']}/returns", ['reason' => 'unused', 'lines' => [['issue_line_id' => $this->issue['lines'][0]['id'], 'qty' => '1']]], $buyer)->assertNotFound();
        $this->postJson("/api/v1/stores/issues/{$this->issue['id']}/acknowledge", [], $buyer)->assertNotFound();
    }

    public function test_another_tenants_documents_and_ids_are_not_found(): void
    {
        $other = $this->otherTenant();
        $headers = $this->headersFor($other['user']);
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('stores'));

        foreach ([
            "stores/requisitions/{$this->requisition['id']}", "stores/issues/{$this->issue['id']}", "stores/issues/{$this->issue['id']}/print", "stores/returns/{$this->return['id']}",
        ] as $uri) {
            $this->getJson("/api/v1/{$uri}", $headers)->assertNotFound();
        }

        foreach (["requisitions/{$this->requisition['id']}/cancel", "requisitions/{$this->requisition['id']}/close", "issues/{$this->issue['id']}/acknowledge"] as $action) {
            $this->postJson("/api/v1/stores/{$action}", ['reason' => 'x'], $headers)->assertNotFound();
        }

        $this->postJson("/api/v1/stores/requisitions/{$this->requisition['id']}/issues", ['lines' => [['requisition_line_id' => $this->requisition['lines'][0]['id'], 'qty' => '1']]], $headers)->assertNotFound();
        $this->postJson("/api/v1/stores/issues/{$this->issue['id']}/returns", ['reason' => 'unused', 'lines' => [['issue_line_id' => $this->issue['lines'][0]['id'], 'qty' => '1']]], $headers)->assertNotFound();

        // Lists show nothing of this tenant; this tenant's ids in a body or filter do not exist.
        foreach (['requisitions', 'issues', 'returns', 'ledger', 'stock', 'consumption'] as $list) {
            $this->assertSame([], $this->getJson("/api/v1/stores/{$list}", $headers)->assertOk()->json('data'), $list);
        }

        $this->getJson('/api/v1/stores/ledger?location='.$this->store->id, $headers)->assertUnprocessable();
        $this->postJson('/api/v1/stores/requisitions', [
            'location_id' => $this->store->id, 'department_id' => $this->kitchen->id, 'lines' => [['item_id' => $this->soap->id, 'qty' => '1']],
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors(['location_id', 'department_id', 'lines.0.item_id']);
    }
}
