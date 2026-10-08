<?php

namespace Tests\Feature\Core\Workflow;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Definitions\FlowDefinitions;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Events\WorkflowNotificationRequested;
use App\Core\Workflow\Events\WorkflowStageEntered;
use App\Core\Workflow\Events\WorkflowStageLeft;
use App\Core\Workflow\Handlers\ApprovalHandler;
use App\Core\Workflow\Handlers\ApprovalStep;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\DocumentWorkflowEvent;
use App\Core\Workflow\Models\DocumentWorkflowLink;
use App\Core\Workflow\Models\DocumentWorkflowToken;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Runtime\WorkflowBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsWorkflows;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\Graphs;
use Tests\Support\Workflow\TestDocuments;
use Tests\Support\Workflow\TestOrderType;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * WF-04..WF-11, APR-09: documents through flows: linear, branching,
 * parallel all/any, entry and exit rules with reasons, stage permissions,
 * return, cancel with created documents, version pinning, time limits,
 * defaults, the approval handler hook, events and audit.
 */
class WorkflowRuntimeTest extends TestCase
{
    use BuildsWorkflows, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        // Wednesday 2026-10-07 10:00 in Nairobi.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07T07:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function move(DocumentWorkflow $workflow, ?string $node = null, ?string $outcome = null, $by = null): DocumentWorkflow
    {
        return $this->inTenant(fn () => $this->engine()->move($workflow, $by ?? $this->owner, $node, $outcome));
    }

    /** @return list<string> */
    private function history(DocumentWorkflow $workflow): array
    {
        return $this->inTenant(fn () => DocumentWorkflowEvent::query()->where('workflow_id', $workflow->id)
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn ($e) => $e->type.($e->node_id ? ':'.$e->node_id : ''))->all());
    }

    private function blocked(callable $fn): WorkflowBlocked
    {
        try {
            $this->inTenant($fn);
        } catch (WorkflowBlocked $e) {
            return $e;
        }

        $this->fail('The move was not blocked.');
    }

    private function refused(callable $fn): ApiException
    {
        try {
            $this->inTenant($fn);
        } catch (ApiException $e) {
            return $e;
        }

        $this->fail('The call was not refused.');
    }

    public function test_a_linear_flow_moves_stage_by_stage_to_its_end(): void
    {
        Event::fake([WorkflowStageEntered::class, WorkflowStageLeft::class, WorkflowCompleted::class]);
        $this->publishFlow(Graphs::linear(['review', 'approve']));

        $workflow = $this->start($this->document());
        $this->assertSame(['review'], $this->at($workflow));
        $this->assertSame(DocumentWorkflow::RUNNING, $workflow->status);

        $workflow = $this->move($workflow);
        $this->assertSame(['approve'], $this->at($workflow));

        $workflow = $this->move($workflow, 'approve');
        $this->assertSame([], $this->at($workflow));
        $this->assertSame(DocumentWorkflow::COMPLETED, $workflow->status);
        $this->assertSame('approved', $workflow->outcome);
        $this->assertNotNull($workflow->completed_at);

        $this->assertSame([
            'started', 'entered:review', 'left:review', 'entered:approve', 'left:approve', 'completed',
        ], $this->history($workflow));

        Event::assertDispatchedTimes(WorkflowStageEntered::class, 2);
        Event::assertDispatched(WorkflowStageLeft::class, fn (WorkflowStageLeft $e) => $e->nodeId === 'review' && $e->how === 'completed' && $e->userId === $this->owner->id);
        Event::assertDispatched(WorkflowCompleted::class, fn (WorkflowCompleted $e) => $e->outcome === 'approved' && $e->documentType === TestRequestType::KEY);

        // AUD-01: start and every move are audited.
        $actions = $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $workflow->id)->orderBy('seq')->pluck('action')->all());
        $this->assertSame(['core.workflow.start', 'core.workflow.move', 'core.workflow.move'], $actions);

        // A finished flow does not move.
        $this->assertSame('workflow_not_running', $this->refused(fn () => $this->engine()->move($workflow, $this->owner))->errorCode);
    }

    public function test_a_document_runs_one_flow_at_a_time_and_unknown_documents_are_not_found(): void
    {
        $this->publishFlow(Graphs::linear(['review']));
        $id = $this->document();
        $this->start($id);

        $this->assertSame('workflow_running', $this->refused(fn () => $this->engine()->start(TestRequestType::KEY, $id, $this->owner))->errorCode);
        $this->assertSame(404, $this->refused(fn () => $this->engine()->start(TestRequestType::KEY, '01a1d0c0-0000-7000-8000-000000000000', $this->owner))->getStatusCode());
        $this->assertSame('unknown_document_type', $this->refused(fn () => $this->engine()->start('core.nothing', $id, $this->owner))->errorCode);
    }

    public function test_branching_follows_the_amount_and_creates_the_next_document(): void
    {
        Event::fake([WorkflowNotificationRequested::class]);
        $this->publishFlow(Graphs::requisition());

        // KES 300,000 is over KES 250,000: the CFO approves too.
        $big = $this->start($this->document(['total' => Graphs::kes(30000000), 'budget' => Graphs::kes(50000000), 'supplier' => 'sup-1']));
        $this->assertSame(['check_budget'], $this->at($big));
        $big = $this->move($big);
        $this->assertSame(['branch_manager'], $this->at($big));
        $big = $this->move($big, outcome: 'approved');
        $this->assertSame(['cfo'], $this->at($big));
        $condition = $this->inTenant(fn () => DocumentWorkflowEvent::query()->where('workflow_id', $big->id)->where('type', 'condition')->sole());
        $this->assertSame('yes', $condition->data['branch']);
        $this->assertSame('over_limit', $condition->node_id);

        $big = $this->move($big, outcome: 'approved');
        $this->assertSame(DocumentWorkflow::COMPLETED, $big->status);
        $this->assertSame('approved', $big->outcome);

        // KES 100,000: straight from the branch manager to the order.
        $small = $this->start($this->document(['total' => Graphs::kes(10000000), 'budget' => Graphs::kes(50000000), 'supplier' => 'sup-2']));
        $small = $this->move($this->move($small), outcome: 'approved');
        $this->assertSame(DocumentWorkflow::COMPLETED, $small->status);
        $this->assertNotContains('entered:cfo', $this->history($small));

        // WF-07: each created a draft order with the mapped fields.
        $orders = $this->inTenant(fn () => TestDocuments::ofType(TestOrderType::KEY));
        $this->assertCount(2, $orders);
        $this->assertSame(['amount' => Graphs::kes(10000000), 'supplier' => 'sup-2'], $orders[1]['values']);
        $link = $this->inTenant(fn () => DocumentWorkflowLink::query()->where('workflow_id', $small->id)->sole());
        $this->assertSame([TestOrderType::KEY, $orders[1]['id'], 'cancel'], [$link->target_type, $link->target_document_id, $link->on_cancel]);

        Event::assertDispatchedTimes(WorkflowNotificationRequested::class, 2);
    }

    public function test_a_rejection_follows_the_rejected_path(): void
    {
        $this->publishFlow(Graphs::requisition());

        $workflow = $this->move($this->start($this->document(['total' => Graphs::kes(100), 'budget' => Graphs::kes(1000)])));
        $workflow = $this->move($workflow, outcome: 'rejected');

        $this->assertSame([DocumentWorkflow::COMPLETED, 'rejected'], [$workflow->status, $workflow->outcome]);
        $this->assertSame([], $this->inTenant(fn () => TestDocuments::ofType(TestOrderType::KEY)));

        // A stage takes no outcome; an approval without a rejected path refuses a rejection.
        $this->publishFlow(Graphs::linear(['review']));
        $stage = $this->start($this->document());
        $this->assertSame('outcome_not_allowed', $this->refused(fn () => $this->engine()->move($stage, $this->owner, null, 'approved'))->errorCode);

        $graph = Graphs::linear(['review']);
        $graph['nodes'][1]['type'] = 'approval';
        $this->publishFlow($graph);
        $approval = $this->start($this->document());
        $this->assertSame('no_rejected_path', $this->refused(fn () => $this->engine()->move($approval, $this->owner, null, 'rejected'))->errorCode);
    }

    public function test_an_entry_rule_blocks_a_mandatory_stage_with_the_reason_and_changes_nothing(): void
    {
        $this->publishFlow(Graphs::requisition());
        $id = $this->document(['total' => Graphs::kes(30000000), 'budget' => Graphs::kes(20000000)]);

        $blocked = $this->blocked(fn () => $this->engine()->start(TestRequestType::KEY, $id, $this->owner));

        $this->assertSame(422, $blocked->getStatusCode());
        $this->assertSame('entry_blocked', $blocked->errorCode);
        $this->assertSame('The document can’t enter “Check budget” yet.', $blocked->getMessage());
        $this->assertSame('check_budget', $blocked->node);
        $this->assertSame(['Document type must be at most Company; it is KES 300,000.00.'], $blocked->reasons);
        $this->assertSame(0, $this->inTenant(fn () => DocumentWorkflow::query()->count()));

        // In French too.
        app()->setLocale('fr');
        $blocked = $this->blocked(fn () => $this->engine()->start(TestRequestType::KEY, $id, $this->owner));
        $this->assertSame('Le document ne peut pas encore entrer dans « Check budget ».', $blocked->getMessage());
        $this->assertStringContainsString('300', $blocked->reasons[0]);
    }

    public function test_an_optional_stage_whose_entry_rule_fails_is_skipped(): void
    {
        $this->publishFlow(Graphs::linear(['travel_desk', 'review'], ['travel_desk' => [
            'mandatory' => false,
            'entry' => ['field' => 'category', 'op' => 'eq', 'value' => 'travel'],
        ]]));

        $goods = $this->start($this->document(['category' => 'goods']));
        $this->assertSame(['review'], $this->at($goods));
        $this->assertContains('skipped:travel_desk', $this->history($goods));

        $travel = $this->start($this->document(['category' => 'travel']));
        $this->assertSame(['travel_desk'], $this->at($travel));
    }

    public function test_an_exit_rule_blocks_leaving_until_it_holds(): void
    {
        $this->publishFlow(Graphs::linear(['receive', 'pay'], ['receive' => [
            'exit' => ['all' => [
                ['field' => 'urgent', 'op' => 'eq', 'value' => true],
                ['field' => 'quantity', 'op' => 'gte', 'value' => 1],
            ]],
        ]]));
        $id = $this->document(['urgent' => false, 'quantity' => 0]);
        $workflow = $this->start($id);

        $blocked = $this->blocked(fn () => $this->engine()->move($workflow, $this->owner));
        $this->assertSame('exit_blocked', $blocked->errorCode);
        $this->assertCount(2, $blocked->reasons);
        $this->assertSame(['receive'], $this->at($workflow));

        $this->inTenant(fn () => TestDocuments::update($id, ['urgent' => true, 'quantity' => '3']));
        $this->assertSame(['pay'], $this->at($this->move($workflow)));
    }

    public function test_stage_roles_decide_who_moves_documents_in_and_out(): void
    {
        $this->publishFlow(Graphs::linear(['review', 'finance'], [
            'review' => ['exit_roles' => ['template:branch_manager']],
            'finance' => ['enter_roles' => ['template:accountant'], 'exit_roles' => ['template:accountant']],
        ]));
        $managerA = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $workflow = $this->start($this->document());

        // RBAC-04: the manager of another branch is refused; the owner holds no named role.
        foreach ([$managerB, $this->owner, $accountant] as $user) {
            $refused = $this->blocked(fn () => $this->engine()->move($workflow, $user));
            $this->assertSame([403, 'stage_forbidden'], [$refused->getStatusCode(), $refused->errorCode]);
        }

        // Branch A's manager may leave review but may not move the document into finance.
        $refused = $this->blocked(fn () => $this->engine()->move($workflow, $managerA));
        $this->assertSame('stage_enter_forbidden', $refused->errorCode);
        $this->assertSame(['review'], $this->at($workflow));

        // A company-wide accountant who may also leave review would; give them review too.
        $this->publishFlow(Graphs::linear(['review', 'finance'], [
            'review' => ['exit_roles' => ['template:branch_manager', 'template:accountant']],
            'finance' => ['enter_roles' => ['template:accountant'], 'exit_roles' => ['template:accountant']],
        ]));
        $next = $this->start($this->document());
        $next = $this->move($next, by: $accountant);
        $this->assertSame(['finance'], $this->at($next));
        $this->assertSame(DocumentWorkflow::COMPLETED, $this->move($next, by: $accountant)->status);
    }

    public function test_a_stage_naming_no_roles_needs_the_types_act_permission(): void
    {
        $this->publishFlow(Graphs::linear(['review']));
        $workflow = $this->start($this->document());
        // Branch managers view parties but cannot edit them (core.party.edit is the act permission).
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $accountant = $this->userWith('accountant', Scope::branch($this->branchA->id));

        $this->assertSame('stage_forbidden', $this->blocked(fn () => $this->engine()->move($workflow, $manager))->errorCode);
        $this->assertSame(DocumentWorkflow::COMPLETED, $this->move($workflow, by: $accountant)->status);
    }

    public function test_parallel_branches_join_when_all_are_done(): void
    {
        $this->publishFlow(Graphs::parallel('all'));
        $workflow = $this->move($this->start($this->document()));
        $this->assertSame(['it', 'payroll'], $this->at($workflow));

        // Two positions: the stage must be named.
        $this->assertSame('choose_stage', $this->refused(fn () => $this->engine()->move($workflow, $this->owner))->errorCode);

        $workflow = $this->move($workflow, 'it');
        $this->assertSame(['payroll'], $this->at($workflow));
        $this->assertSame(1, $this->inTenant(fn () => DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->where('status', 'waiting')->count()));

        $workflow = $this->move($workflow, 'payroll');
        $this->assertSame(['close'], $this->at($workflow));
        $this->assertContains('joined:join', $this->history($workflow));
        $this->assertSame(0, $this->inTenant(fn () => DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->where('status', 'waiting')->count()));

        $this->assertSame(DocumentWorkflow::COMPLETED, $this->move($workflow)->status);
    }

    public function test_parallel_any_continues_at_the_first_branch_and_closes_the_others(): void
    {
        Event::fake([WorkflowStageLeft::class]);
        $this->publishFlow(Graphs::parallel('any'));
        $workflow = $this->move($this->start($this->document()));

        $workflow = $this->move($workflow, 'payroll');
        $this->assertSame(['close'], $this->at($workflow));
        $it = $this->inTenant(fn () => DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->where('node_id', 'it')->sole());
        $this->assertSame(DocumentWorkflowToken::CANCELLED, $it->status);
        Event::assertDispatched(WorkflowStageLeft::class, fn (WorkflowStageLeft $e) => $e->nodeId === 'it' && $e->how === 'joined');

        $this->assertSame(DocumentWorkflow::COMPLETED, $this->move($workflow)->status);
    }

    public function test_return_sends_the_document_back_with_a_reason(): void
    {
        $this->publishFlow(Graphs::linear(['draft', 'review', 'approve'], ['draft' => [
            // Returning skips entry rules: the document already passed them.
            'entry' => ['field' => 'urgent', 'op' => 'eq', 'value' => true],
        ]]));
        $id = $this->document(['urgent' => true]);
        $workflow = $this->move($this->move($this->start($id)));
        $this->assertSame(['approve'], $this->at($workflow));
        $this->inTenant(fn () => TestDocuments::update($id, ['urgent' => false]));

        // Only to a stage it passed.
        $this->assertSame('return_target', $this->refused(fn () => $this->engine()->returnTo($workflow, $this->owner, 'approve', 'Again'))->errorCode);
        $this->assertSame('return_target', $this->refused(fn () => $this->engine()->returnTo($workflow, $this->owner, 'end', 'Again'))->errorCode);

        $workflow = $this->inTenant(fn () => $this->engine()->returnTo($workflow, $this->owner, 'draft', 'Quantities are wrong'));
        $this->assertSame(['draft'], $this->at($workflow));
        $returned = $this->inTenant(fn () => DocumentWorkflowEvent::query()->where('workflow_id', $workflow->id)->where('type', 'returned')->sole());
        $this->assertSame(['Quantities are wrong', 'draft', ['approve']], [$returned->reason, $returned->node_id, $returned->data['from']]);
        $this->assertSame('core.workflow.return', $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $workflow->id)->orderByDesc('seq')->value('action')));

        // And on again from there.
        $this->assertSame(['review'], $this->at($this->move($workflow)));
    }

    public function test_return_into_one_parallel_branch_is_refused_but_before_the_split_is_allowed(): void
    {
        $this->publishFlow(Graphs::parallel('all'));
        $workflow = $this->move($this->start($this->document()));
        $workflow = $this->move($workflow, 'it');

        $this->assertSame('return_target', $this->refused(fn () => $this->engine()->returnTo($workflow, $this->owner, 'it', 'Redo'))->errorCode);

        $workflow = $this->inTenant(fn () => $this->engine()->returnTo($workflow, $this->owner, 'prepare', 'Start over'));
        $this->assertSame(['prepare'], $this->at($workflow));
        $this->assertSame(0, $this->inTenant(fn () => DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->whereIn('status', ['active', 'waiting'])->where('node_id', '<>', 'prepare')->count()));

        $this->assertSame(['it', 'payroll'], $this->at($this->move($workflow)));
    }

    public function test_cancel_keeps_or_cancels_the_documents_the_flow_created(): void
    {
        Event::fake([WorkflowCancelled::class]);
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'order', 'type' => 'action', 'name' => 'Order', 'action' => 'create_document', 'config' => ['mapping' => 'order', 'on_cancel' => 'cancel']],
                ['id' => 'copy', 'type' => 'action', 'name' => 'Copy', 'action' => 'create_document', 'config' => ['mapping' => 'order', 'on_cancel' => 'keep']],
                ['id' => 'review', 'type' => 'stage', 'name' => 'Review'],
                ['id' => 'end', 'type' => 'end'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'order'], ['from' => 'order', 'to' => 'copy'],
                ['from' => 'copy', 'to' => 'review'], ['from' => 'review', 'to' => 'end'],
            ],
        ];
        $this->publishFlow($graph);
        $workflow = $this->start($this->document(['total' => Graphs::kes(500)]));

        // Someone who can neither move the stage nor act on the type is refused.
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->assertSame(403, $this->refused(fn () => $this->engine()->cancel($workflow, $cashier, 'No'))->getStatusCode());

        $workflow = $this->inTenant(fn () => $this->engine()->cancel($workflow, $this->owner, 'Not needed any more'));

        $this->assertSame([DocumentWorkflow::CANCELLED, 'Not needed any more', $this->owner->id], [$workflow->status, $workflow->cancel_reason, $workflow->cancelled_by]);
        $this->assertSame([], $this->at($workflow));
        $orders = collect($this->inTenant(fn () => TestDocuments::ofType(TestOrderType::KEY)))->pluck('status')->all();
        $this->assertSame(['cancelled', 'draft'], $orders);
        $this->assertSame(1, $this->inTenant(fn () => DocumentWorkflowLink::query()->whereNotNull('cancelled_at')->count()));
        Event::assertDispatched(WorkflowCancelled::class, fn (WorkflowCancelled $e) => $e->reason === 'Not needed any more');
        $this->assertSame('core.workflow.cancel', $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $workflow->id)->orderByDesc('seq')->value('action')));

        // A cancelled flow cannot be cancelled again; a new one can start.
        $this->assertSame('workflow_not_running', $this->refused(fn () => $this->engine()->cancel($workflow, $this->owner, 'Again'))->errorCode);
    }

    public function test_documents_in_progress_stay_on_their_version_when_a_new_one_is_published(): void
    {
        $v1 = $this->publishFlow(Graphs::linear(['old_a', 'old_b']));
        $first = $this->start($this->document());

        $v2 = $this->publishFlow(Graphs::linear(['new_review']));
        $this->assertSame([1, 2], [$v1->version, $v2->version]);
        $this->assertSame('archived', $this->inTenant(fn () => $v1->fresh()->status));

        $second = $this->start($this->document());
        $this->assertSame(['new_review'], $this->at($second));
        $this->assertSame($v2->id, $second->version_id);

        // The first document finishes on version 1's stages.
        $first = $this->move($first);
        $this->assertSame(['old_b'], $this->at($first));
        $this->assertSame($v1->id, $first->version_id);
        $this->assertSame(DocumentWorkflow::COMPLETED, $this->move($first)->status);
    }

    public function test_a_company_flow_wins_over_the_flow_for_every_company(): void
    {
        $this->publishFlow(Graphs::linear(['everyone']));
        $this->publishFlow(Graphs::linear(['acme_only']), $this->acme);
        $other = $this->inTenant(fn () => $this->company('Other'));

        $this->assertSame(['acme_only'], $this->at($this->start($this->document())));
        $this->assertSame(['everyone'], $this->at($this->start($this->document([], new DocumentScope($other->id)))));
    }

    public function test_without_a_published_flow_the_types_default_is_published_and_used(): void
    {
        $workflow = $this->start($this->document());

        $this->assertSame(['review'], $this->at($workflow));
        $definition = $this->inTenant(fn () => WorkflowDefinition::query()->sole());
        $this->assertSame([TestRequestType::KEY, $this->acme->id], [$definition->document_type, $definition->company_id]);
        $this->assertSame(1, $this->inTenant(fn () => $definition->published()->value('version')));
        $this->assertSame('core.workflow.publish', $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $definition->id)->value('action')));

        // The default names the Admin and Owner roles (template:...): the owner may move it.
        $this->assertSame(DocumentWorkflow::COMPLETED, $this->move($workflow)->status);

        // A type without a default and without a flow is refused.
        $this->assertSame('workflow_not_configured', $this->refused(fn () => $this->engine()->start(TestOrderType::KEY, TestDocuments::create(TestOrderType::KEY, [], new DocumentScope($this->acme->id)), $this->owner))->errorCode);
    }

    public function test_time_limits_set_due_times_in_business_hours_and_feed_the_overdue_query(): void
    {
        $this->publishFlow(Graphs::linear(['review', 'pay'], [
            'review' => ['due' => ['amount' => 8, 'unit' => 'business_hours']],
            'pay' => ['due' => ['amount' => 2, 'unit' => 'business_days']],
        ]));
        $workflow = $this->start($this->document());

        $token = $this->inTenant(fn () => DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->sole());
        // Wednesday 10:00 Nairobi + 8 business hours = Thursday 09:00 Nairobi.
        $this->assertSame('2026-10-08T06:00:00Z', $token->due_at->toIso8601ZuluString());

        $this->assertSame(0, $this->inTenant(fn () => $this->engine()->overdue()->count()));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08T06:00:01Z'));
        $overdue = $this->inTenant(fn () => $this->engine()->overdue()->get());
        $this->assertSame([$token->id], $overdue->pluck('id')->all());

        $status = $this->inTenant(fn () => $this->engine()->status($workflow, $this->owner));
        $this->assertTrue($status['current'][0]['overdue']);
        $this->assertSame(82801, $status['current'][0]['seconds_in_stage']);

        // Thursday 09:00:01 + 2 business days = Monday 09:00:01.
        $workflow = $this->move($workflow);
        $pay = $this->inTenant(fn () => DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->where('node_id', 'pay')->sole());
        $this->assertSame('2026-10-12T06:00:01Z', $pay->due_at->toIso8601ZuluString());
    }

    public function test_status_shows_where_the_document_is_who_holds_it_and_its_history(): void
    {
        $this->publishFlow(Graphs::linear(['review'], ['review' => ['exit_roles' => ['template:accountant']]]));
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->userWith('accountant', Scope::branch($this->branchB->id)); // not at branch A
        $workflow = $this->start($this->document());

        $status = $this->inTenant(fn () => $this->engine()->status($workflow, $accountant));

        $this->assertSame('running', $status['status']);
        $this->assertSame(1, $status['version']['number']);
        $this->assertSame(['review', 'Review', 'stage', 'active'], [$status['current'][0]['node_id'], $status['current'][0]['name'], $status['current'][0]['type'], $status['current'][0]['status']]);
        $this->assertSame([$this->roles->get('accountant')->id], array_column($status['current'][0]['holders']['roles'], 'id'));
        $this->assertSame([$accountant->id], array_column($status['current'][0]['holders']['users'], 'id'));
        $this->assertTrue($status['current'][0]['can_move']);
        $this->assertSame(['started', 'entered'], array_column($status['history'], 'type'));
        $this->assertSame(['id' => $this->owner->id, 'name' => 'Owner'], $status['started_by']);

        $this->assertFalse($this->inTenant(fn () => $this->engine()->status($workflow, $this->owner))['current'][0]['can_move']);
    }

    public function test_an_approval_handler_can_take_over_approval_nodes(): void
    {
        $handler = new class implements ApprovalHandler
        {
            /** @var list<string> */
            public array $calls = [];

            public function validate(array $node, DocumentType $type): array
            {
                return [];
            }

            public function entered(ApprovalStep $step): void
            {
                $this->calls[] = 'entered:'.$step->node['id'];
            }

            public function left(ApprovalStep $step, string $why): void
            {
                $this->calls[] = "left:{$step->node['id']}:{$why}";
            }

            public function allowsManualCompletion(ApprovalStep $step): bool
            {
                return false;
            }

            public function holders(ApprovalStep $step): ?array
            {
                return ['roles' => [], 'users' => [$step->workflow->started_by]];
            }
        };
        $this->app->instance(ApprovalHandler::class, $handler);

        $graph = Graphs::linear(['manager']);
        $graph['nodes'][1]['type'] = 'approval';
        $this->publishFlow($graph);
        $workflow = $this->start($this->document());

        $refused = $this->blocked(fn () => $this->engine()->move($workflow, $this->owner));
        $this->assertSame([403, 'approval_pending'], [$refused->getStatusCode(), $refused->errorCode]);

        $status = $this->inTenant(fn () => $this->engine()->status($workflow, $this->owner));
        $this->assertSame([$this->owner->id], array_column($status['current'][0]['holders']['users'], 'id'));
        $this->assertFalse($status['current'][0]['can_move']);

        $token = $status['current'][0]['token_id'];
        $workflow = $this->inTenant(fn () => $this->engine()->completeNode($workflow, $token, 'approved', null));
        $this->assertSame(DocumentWorkflow::COMPLETED, $workflow->status);
        $this->assertSame(['entered:manager', 'left:manager:completed'], $handler->calls);
    }

    public function test_flow_rows_of_another_tenant_are_invisible(): void
    {
        $this->publishFlow(Graphs::linear(['review']));
        $workflow = $this->start($this->document());
        $other = $this->otherTenant();

        $this->asTenant($other['user']->tenant_id, function () use ($workflow) {
            $this->assertNull(DocumentWorkflow::query()->find($workflow->id));
            $this->assertSame(0, DocumentWorkflowEvent::query()->count());
            $this->assertSame(0, WorkflowDefinition::query()->count());
            $this->assertNull(app(FlowDefinitions::class)->publishedFor(TestRequestType::KEY, null));
        });
    }
}
