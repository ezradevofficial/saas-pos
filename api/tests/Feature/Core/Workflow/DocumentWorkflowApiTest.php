<?php

namespace Tests\Feature\Core\Workflow;

use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsWorkflows;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\Graphs;
use Tests\Support\Workflow\TestDocuments;
use Tests\Support\Workflow\TestOrderType;
use Tests\TestCase;

/**
 * WF-04, WF-08, WF-10, WF-11: a document's flow through the API: status
 * and history, move (with blocked moves explained), return, cancel;
 * permissions (RBAC-04) and other tenants.
 */
class DocumentWorkflowApiTest extends TestCase
{
    use BuildsWorkflows, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    public function test_status_move_return_and_cancel(): void
    {
        $this->publishFlow(Graphs::linear(['draft', 'review', 'approve']));
        $id = $this->document();
        $this->start($id);

        $status = $this->getJson($this->workflowUrl($id), $this->headersFor())->assertOk();
        $status->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.document_id', $id)
            ->assertJsonPath('data.current.0.node_id', 'draft')
            ->assertJsonPath('data.current.0.holders.permission', 'core.party.edit')
            ->assertJsonPath('data.current.0.can_move', true)
            ->assertJsonPath('data.version.number', 1)
            // WF-10: the status page's heading; this type has no summary or page of its own.
            ->assertJsonPath('data.document', ['type_label' => 'Workflows', 'number' => null, 'title' => null, 'company_id' => $this->acme->id, 'link' => null]);

        $this->postJson($this->workflowUrl($id, '/move'), [], $this->headersFor())->assertOk()->assertJsonPath('data.current.0.node_id', 'review');
        $this->postJson($this->workflowUrl($id, '/move'), ['node' => 'review'], $this->headersFor())->assertOk()->assertJsonPath('data.current.0.node_id', 'approve');
        $this->postJson($this->workflowUrl($id, '/move'), ['node' => 'draft'], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'stage_not_current');
        $this->postJson($this->workflowUrl($id, '/move'), ['outcome' => 'maybe'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('outcome');

        $this->postJson($this->workflowUrl($id, '/return'), ['node' => 'draft'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('reason');
        $returned = $this->postJson($this->workflowUrl($id, '/return'), ['node' => 'draft', 'reason' => 'Wrong supplier'], $this->headersFor())->assertOk();
        $returned->assertJsonPath('data.current.0.node_id', 'draft');
        $history = collect($returned->json('data.history'));
        $this->assertSame('Wrong supplier', $history->firstWhere('type', 'returned')['reason']);
        $this->assertSame(['id' => $this->owner->id, 'name' => 'Owner'], $history->firstWhere('type', 'returned')['user']);

        $this->postJson($this->workflowUrl($id, '/cancel'), [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('reason');
        $cancelled = $this->postJson($this->workflowUrl($id, '/cancel'), ['reason' => 'Duplicate request'], $this->headersFor())->assertOk();
        $cancelled->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Duplicate request')->assertJsonPath('data.current', []);

        // The ended flow is still shown; it no longer moves.
        $this->getJson($this->workflowUrl($id), $this->headersFor())->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($this->workflowUrl($id, '/move'), [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'workflow_not_running');
    }

    public function test_a_blocked_move_answers_422_with_the_reasons_in_the_readers_language(): void
    {
        $this->publishFlow(Graphs::linear(['receive', 'pay'], ['pay' => [
            'entry' => ['field' => 'total', 'op' => 'lte', 'value' => Graphs::kes(100000)],
        ]]));
        $id = $this->document(['total' => Graphs::kes(250000)]);
        $this->start($id);

        $this->postJson($this->workflowUrl($id, '/move'), [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'entry_blocked')
            ->assertJsonPath('message', 'The document can’t enter “Pay” yet.')
            ->assertJsonPath('node', 'pay')
            ->assertJsonPath('reasons.0', 'Document type must be at most KES 1,000.00; it is KES 2,500.00.');

        $this->postJson($this->workflowUrl($id, '/move'), [], $this->headersFor(null) + ['Accept-Language' => 'fr'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Le document ne peut pas encore entrer dans « Pay ».');

        $this->getJson($this->workflowUrl($id), $this->headersFor())->assertJsonPath('data.current.0.node_id', 'receive');
    }

    public function test_the_flow_creates_documents_and_lists_them(): void
    {
        $this->publishFlow(Graphs::requisition());
        $id = $this->document(['total' => Graphs::kes(100), 'budget' => Graphs::kes(1000)]);
        $this->start($id);

        $this->postJson($this->workflowUrl($id, '/move'), [], $this->headersFor())->assertOk();
        $done = $this->postJson($this->workflowUrl($id, '/move'), ['outcome' => 'approved'], $this->headersFor())->assertOk();

        $done->assertJsonPath('data.status', 'completed')->assertJsonPath('data.outcome', 'approved');
        $order = $this->inTenant(fn () => TestDocuments::ofType(TestOrderType::KEY))[0];
        $done->assertJsonPath('data.created_documents.0.document_id', $order['id'])->assertJsonPath('data.created_documents.0.on_cancel', 'cancel');
        $this->assertSame('no', collect($done->json('data.history'))->firstWhere('type', 'condition')['data']['branch']);
    }

    public function test_who_may_see_and_move_a_document(): void
    {
        $this->publishFlow(Graphs::linear(['review'], ['review' => ['exit_roles' => ['template:accountant']]]));
        $id = $this->document();
        $this->start($id);

        // Branch B's manager does not reach branch A's document: not found.
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->getJson($this->workflowUrl($id), $this->headersFor($managerB))->assertNotFound();
        $this->postJson($this->workflowUrl($id, '/move'), [], $this->headersFor($managerB))->assertNotFound();

        // Branch A's manager sees it (core.party.view) but holds no role on the stage: 403 with the reason.
        $managerA = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson($this->workflowUrl($id), $this->headersFor($managerA))->assertOk()->assertJsonPath('data.current.0.can_move', false);
        $this->postJson($this->workflowUrl($id, '/move'), [], $this->headersFor($managerA))
            ->assertForbidden()->assertJsonPath('code', 'stage_forbidden')
            ->assertJsonPath('message', 'You can’t move documents out of “Review”. Ask someone with a role allowed on that stage.');
        $this->postJson($this->workflowUrl($id, '/cancel'), ['reason' => 'No'], $this->headersFor($managerA))->assertForbidden();

        // M1 regression: designing flows (core.workflow.view) is not reading documents.
        $designer = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Flow designer', ['core.workflow.view', 'core.workflow.edit']), Scope::tenant());

            return $user;
        });
        $this->getJson($this->workflowUrl($id), $this->headersFor($designer))->assertNotFound();
        $this->getJson('/api/v1/workflows', $this->headersFor($designer))->assertOk();

        // A company accountant may.
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->postJson($this->workflowUrl($id, '/move'), [], $this->headersFor($accountant))->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_unknown_types_documents_and_other_tenants_are_not_found(): void
    {
        $this->publishFlow(Graphs::linear(['review']));
        $id = $this->document();
        $this->start($id);
        $withoutFlow = $this->document();
        $other = $this->otherTenant();
        $theirs = $this->headersFor($other['user']);

        $this->getJson('/api/v1/document-workflows/core.nothing/'.$id, $this->headersFor())->assertNotFound();
        $this->getJson($this->workflowUrl($withoutFlow), $this->headersFor())->assertNotFound();
        $this->getJson($this->workflowUrl('not-a-uuid'), $this->headersFor())->assertNotFound();

        $this->getJson($this->workflowUrl($id), $theirs)->assertNotFound();
        $this->postJson($this->workflowUrl($id, '/move'), [], $theirs)->assertNotFound();
        $this->postJson($this->workflowUrl($id, '/return'), ['node' => 'review', 'reason' => 'x'], $theirs)->assertNotFound();
        $this->postJson($this->workflowUrl($id, '/cancel'), ['reason' => 'x'], $theirs)->assertNotFound();
        $this->getJson($this->workflowUrl($id), $this->headersFor())->assertOk()->assertJsonPath('data.status', 'running');
    }
}
