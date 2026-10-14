<?php

namespace Modules\Stores\Tests;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Notification;
use Modules\Stores\Models\Requisition;
use Modules\Stores\Tests\Concerns\BuildsStores;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// STR-02, STR-03, STR-04, STR-15, STR-17, WF-01, APR-02, APR-07:
// requisitions from departments, approved by the department head through
// the workflow engine, cancelled and closed.
class RequisitionsTest extends TestCase
{
    use BuildsStores, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpStores();
    }

    public function test_a_requisition_is_numbered_converted_to_base_units_and_goes_to_the_department_head(): void
    {
        $requisition = $this->requisition([
            ['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'qty' => '2', 'note' => 'For the week'],
            ['item_id' => $this->sugar->id, 'qty' => '1.5'],
        ], by: $this->storekeeper, extra: ['cost_centre_id' => $this->operations->id, 'needed_by' => '2026-11-01', 'note' => 'Weekly'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.department.name', 'Kitchen')
            ->assertJsonPath('data.cost_centre.code', 'OPS')
            ->assertJsonPath('data.lines.0.qty', '2')
            ->assertJsonPath('data.lines.0.qty_base', '24')
            ->assertJsonPath('data.lines.0.qty_open', '2')
            ->assertJsonPath('data.lines.1.qty_base', '1.5')
            ->assertJsonPath('data.can.issue', false)
            ->json('data');

        $this->assertMatchesRegularExpression('/^REQ-A-\d{4}-00001$/', $requisition['number']);

        // APR-02: the department head is asked, even at another branch; never the requester (APR-07).
        $request = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $requisition['id'])->sole());
        $assigned = $this->inTenant(fn () => ApprovalAssignment::query()->where('request_id', $request->id)->pluck('user_id')->all());
        $this->assertSame([$this->head->id], $assigned);

        $this->approve($requisition['id']);
        $this->getJson("/api/v1/stores/requisitions/{$requisition['id']}", $this->headersFor($this->storekeeper))
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.can.issue', true)->assertJsonPath('data.can.close', true);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'stores.requisition.create')->exists()));
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'stores.requisition.update')->exists()));
    }

    public function test_without_a_department_head_the_branch_managers_at_the_store_decide_and_can_reject(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $requisition = $this->requisition([['item_id' => $this->soap->id, 'qty' => '3']], $this->housekeeping, $this->storekeeper)->assertCreated()->json('data');

        $request = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $requisition['id'])->sole());
        $this->assertSame([$manager->id], $this->inTenant(fn () => ApprovalAssignment::query()->where('request_id', $request->id)->pluck('user_id')->all()));

        $this->postJson("/api/v1/approvals/{$request->id}/reject", ['comment' => 'Not this week'], $this->headersFor($manager))->assertOk();
        $this->getJson("/api/v1/stores/requisitions/{$requisition['id']}", $this->headersFor())->assertJsonPath('data.status', 'rejected');
        $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], '1']])->assertStatus(409)->assertJsonPath('code', 'document_not_open');
    }

    public function test_requests_are_checked_against_the_store_its_company_and_the_items(): void
    {
        $outlet = $this->locationA;
        $other = $this->inTenant(fn () => $this->company('Other'));
        $foreignDepartment = $this->inTenant(fn () => Department::create(['company_id' => $other->id, 'code' => 'X', 'name' => 'Elsewhere']));

        $this->requisition([['item_id' => $this->soap->id, 'qty' => '1']], store: $outlet)->assertUnprocessable()->assertJsonPath('code', 'not_a_store');
        $this->requisition([['item_id' => $this->soap->id, 'qty' => '1']], $foreignDepartment)->assertUnprocessable()->assertJsonPath('code', 'department_other_company');
        $this->requisition([['item_id' => $this->service->id, 'qty' => '1']])->assertUnprocessable()->assertJsonPath('code', 'not_a_stock_item');
        $this->requisition([['item_id' => $this->soap->id, 'uom_id' => $this->kg->id, 'qty' => '1']])->assertUnprocessable()->assertJsonPath('code', 'uom_not_on_item');
        $this->requisition([['item_id' => $this->soap->id, 'qty' => '1.5']])->assertUnprocessable()->assertJsonPath('code', 'qty_precision');
        $this->requisition([['item_id' => $this->soap->id, 'qty' => '0']])->assertUnprocessable()->assertJsonPath('code', 'qty_invalid');
        $this->assertSame(0, $this->inTenant(fn () => Requisition::query()->count()));
    }

    public function test_a_pending_requisition_is_cancelled_with_its_flow_and_a_partly_issued_one_is_closed(): void
    {
        $pending = $this->requisition([['item_id' => $this->soap->id, 'qty' => '3']], by: $this->storekeeper)->assertCreated()->json('data');
        $this->postJson("/api/v1/stores/requisitions/{$pending['id']}/cancel", ['reason' => 'Duplicate'], $this->headersFor($this->storekeeper))
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Duplicate');
        $this->assertSame('cancelled', $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $pending['id'])->value('status')));

        $this->opening($this->soap, '10');
        $approved = $this->approved([['item_id' => $this->soap->id, 'qty' => '5']]);
        $this->issue($approved['id'], [[$approved['lines'][0]['id'], '2']])->assertCreated();

        // Something was issued: no longer cancellable, but it can be closed.
        $this->postJson("/api/v1/stores/requisitions/{$approved['id']}/cancel", ['reason' => 'No'], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'document_not_open');
        $this->postJson("/api/v1/stores/requisitions/{$approved['id']}/close", ['reason' => 'Enough for now'], $this->headersFor($this->storekeeper))
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.lines.0.qty_open', '3');
        $this->issue($approved['id'], [[$approved['lines'][0]['id'], '1']])->assertStatus(409);
    }

    public function test_actions_need_their_permission_at_the_store(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->store->id));
        $elsewhere = $this->userWith('storekeeper', Scope::location($this->storeB->id));
        $pending = $this->requisition([['item_id' => $this->soap->id, 'qty' => '3']], by: $this->storekeeper)->assertCreated()->json('data');

        $this->requisition([['item_id' => $this->soap->id, 'qty' => '1']], by: $cashier)->assertForbidden();
        // A storekeeper of store B may ask store B, not the main store, and never sees its requisitions.
        $this->requisition([['item_id' => $this->soap->id, 'qty' => '1']], by: $elsewhere)->assertForbidden();
        $own = $this->requisition([['item_id' => $this->soap->id, 'qty' => '1']], by: $elsewhere, store: $this->storeB)->assertCreated()->json('data.id');
        $this->getJson("/api/v1/stores/requisitions/{$pending['id']}", $this->headersFor($elsewhere))->assertNotFound();
        $this->postJson("/api/v1/stores/requisitions/{$pending['id']}/cancel", ['reason' => 'x'], $this->headersFor($elsewhere))->assertNotFound();
        $this->assertSame([$own], array_column($this->getJson('/api/v1/stores/requisitions', $this->headersFor($elsewhere))->assertOk()->json('data'), 'id'));
        $this->getJson('/api/v1/stores/requisitions', $this->headersFor($cashier))->assertForbidden();
        // The storekeeper requests but never approves (STR-03, RBAC-03).
        $request = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $pending['id'])->sole());
        $this->postJson("/api/v1/approvals/{$request->id}/approve", [], $this->headersFor($this->storekeeper))->assertStatus(403);
    }

    public function test_the_list_filters_by_status_department_and_item_and_exports(): void
    {
        $this->requisition([['item_id' => $this->soap->id, 'qty' => '1']], by: $this->storekeeper)->assertCreated();
        $this->requisition([['item_id' => $this->sugar->id, 'qty' => '1']], $this->housekeeping, $this->storekeeper)->assertCreated();

        $this->assertCount(2, $this->getJson('/api/v1/stores/requisitions', $this->headersFor())->assertOk()->json('data'));
        $this->assertSame(['Kitchen'], array_column(array_column($this->getJson('/api/v1/stores/requisitions?department='.$this->kitchen->id, $this->headersFor())->json('data'), 'department'), 'name'));
        $this->assertSame(['Housekeeping'], array_column(array_column($this->getJson('/api/v1/stores/requisitions?item='.$this->sugar->id, $this->headersFor())->json('data'), 'department'), 'name'));
        $this->assertCount(0, $this->getJson('/api/v1/stores/requisitions?status=approved', $this->headersFor())->json('data'));
        $csv = $this->get('/api/v1/stores/requisitions?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('Housekeeping', $csv);
    }
}
