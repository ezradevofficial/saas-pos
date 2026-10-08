<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Approvals\EngineApprovals;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Runtime\Rules;
use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Handlers\ApprovalHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\FakeHostResolver;
use Tests\Support\Workflow\Graphs;
use Tests\Support\Workflow\TestDocuments;
use Tests\Support\Workflow\TestOrderType;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * AUTO-03: each action does what it says through the document type's
 * capabilities (never a module's tables), as the rule's last editor, in
 * one transaction (a failure undoes the earlier actions); notifications
 * reach only people who may see the document; webhooks are signed, sent
 * to the checked address only, and keep the status and the start of the
 * answer.
 */
class AutomationActionsTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    private FakeHostResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->setUpAutomation();
        $this->dns = new FakeHostResolver(['hooks.example.com' => [['93.184.216.34']], 'intranet.example.com' => [['10.0.0.7']]]);
        $this->app->instance(HostResolver::class, $this->dns);
    }

    public function test_update_field_writes_through_the_type(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true], ['type' => 'update_field', 'field' => 'note', 'value' => null]]);

        $id = $this->createTask(['note' => 'old']);

        $this->assertTrue($this->taskValues($id)['urgent']);
        $this->assertNull($this->taskValues($id)['note']);
        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::SUCCEEDED, $run->outcome);
        $this->assertSame([['field' => 'urgent'], ['field' => 'note']], array_column($run->actions, 'result'));
    }

    public function test_change_stage_moves_and_returns_through_the_engine_as_the_rules_editor(): void
    {
        $this->publishFlow(Graphs::linear(['review', 'check', 'close']));
        $this->inTenant(fn () => app(Rules::class)->create([
            'name' => 'Skip review', 'document_type' => TestRequestType::KEY, 'enabled' => true,
            'trigger' => ['type' => 'stage_entered', 'stage' => 'review'],
            'actions' => [['type' => 'change_stage', 'mode' => 'move', 'stage' => 'review']],
        ], $this->owner));
        $this->inTenant(fn () => app(Rules::class)->create([
            'name' => 'Send back', 'document_type' => TestRequestType::KEY, 'enabled' => true,
            'trigger' => ['type' => 'stage_entered', 'stage' => 'close'],
            'actions' => [['type' => 'change_stage', 'mode' => 'return', 'stage' => 'check', 'reason' => 'Needs a second look']],
        ], $this->owner));

        $workflow = $this->start($this->document());
        $this->assertSame(['check'], $this->at($workflow), 'the rule moved it on from review');

        $this->inTenant(fn () => $this->engine()->move($workflow, $this->owner));
        $this->assertSame(['check'], $this->at($workflow), 'entering close sent it back to check');
        $this->assertSame('Needs a second look', $this->inTenant(fn () => $workflow->events()->where('type', 'returned')->value('reason')));
        $this->assertSame(2, $this->inTenant(fn () => AutomationRun::query()->where('outcome', 'succeeded')->count()));
    }

    public function test_change_stage_cannot_complete_an_approval(): void
    {
        // The approvals service decides approval steps (as in production).
        $this->app->bind(ApprovalHandler::class, EngineApprovals::class);
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->publishFlow([
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'approve', 'type' => 'approval', 'name' => 'Manager approves', 'approval' => ['approver' => ['type' => 'user', 'user_id' => $manager->id]]],
                ['id' => 'end', 'type' => 'end', 'outcome' => 'approved'],
            ],
            'edges' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end', 'branch' => 'approved']],
        ]);
        $rule = $this->inTenant(fn () => app(Rules::class)->create([
            'name' => 'Approve for them', 'document_type' => TestRequestType::KEY, 'enabled' => true,
            'trigger' => ['type' => 'stage_entered', 'stage' => 'approve'],
            'actions' => [['type' => 'change_stage', 'mode' => 'move', 'stage' => 'approve']],
        ], $this->owner));

        $workflow = $this->start($this->document());

        $this->assertSame(['approve'], $this->at($workflow), 'still waiting for the approver');
        $run = $this->runs($rule)->sole();
        $this->assertSame([AutomationRun::FAILED, 'action_failed', 1], [$run->outcome, $run->error_code, $run->attempts]);
        $this->assertStringContainsString('approvals', $run->error);
    }

    public function test_change_stage_without_a_running_workflow_fails_at_once(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'change_stage', 'mode' => 'move']]);

        $this->createTask();

        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::FAILED, $run->outcome);
        $this->assertSame(1, $run->attempts, 'a refusal is not retried');
        $this->assertSame('The document isn’t in a running workflow, so its stage can’t change.', $run->error);
    }

    public function test_assign_user_sets_the_field_only_for_someone_who_may_see_the_document(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $outsider = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $assign = $this->saveRule(['type' => 'record_created'], [['type' => 'assign_user', 'field' => 'owner', 'user' => $manager->id]]);

        $id = $this->createTask();
        $this->assertSame($manager->id, $this->taskValues($id)['owner']);
        $this->assertSame(['field' => 'owner', 'user_id' => $manager->id], $this->runs($assign)->sole()->actions[0]['result']);

        $this->inTenant(fn () => app(Rules::class)->archive($assign, $this->owner));
        $blind = $this->saveRule(['type' => 'record_created'], [['type' => 'assign_user', 'field' => 'owner', 'user' => $outsider->id]]);
        $other = $this->createTask();

        $this->assertArrayNotHasKey('owner', $this->taskValues($other));
        $this->assertSame(AutomationRun::FAILED, $this->runs($blind)->sole()->outcome);
        $this->assertStringContainsString('can’t see this document', $this->runs($blind)->sole()->error);
    }

    public function test_notify_reaches_role_holders_named_users_and_the_person_field_who_may_see_the_document(): void
    {
        $managerA = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $assignee = $this->userWith('branch_manager', Scope::company($this->acme->id));
        $blindNamed = $this->userWith('cashier', Scope::location($this->locationB->id));
        $rule = $this->saveRule(['type' => 'record_created'], [[
            'type' => 'notify',
            'to' => ['role:branch_manager', "user:{$blindNamed->id}", 'field:owner'],
            'subject' => '{document_type}: {title}',
            'message' => 'Rule {rule_name} saw {title} for {amount}.',
        ]], ['name' => 'Big tasks']);

        $id = $this->createTask(['owner' => $assignee->id, 'amount' => $this->kes(500000)]);

        $result = $this->runs($rule)->sole()->actions[0]['result'];
        $this->assertEqualsCanonicalizing([$managerA->id, $assignee->id], $result['sent']);
        $this->assertNotContains($managerB->id, $result['sent']);
        $this->assertSame([$blindNamed->id], $result['skipped']);
        $this->assertSame('cannot_see_document', $result['skipped_reason']);

        $this->inTenant(function () use ($managerA, $id) {
            $note = InAppNotification::query()->where('user_id', $managerA->id)->sole();
            $this->assertSame('core.automation.notify', $note->event_type);
            $this->assertSame('Document: Count stock', $note->subject);
            $this->assertStringContainsString('Rule Big tasks saw Count stock for KES 5,000.00.', $note->body);
            $this->assertSame('/tasks/'.$id, $note->link);
        });
    }

    public function test_create_document_makes_a_draft_of_the_target_type_from_mapped_and_fixed_values(): void
    {
        $supplier = (string) Str::uuid7();
        $rule = $this->saveRule(['type' => 'record_created'], [[
            'type' => 'create_document', 'target' => TestOrderType::KEY,
            'mapping' => ['amount' => 'amount'], 'values' => ['supplier' => $supplier],
        ]]);

        $this->createTask(['amount' => $this->kes(250000)]);

        $result = $this->runs($rule)->sole()->actions[0]['result'];
        $this->assertSame(TestOrderType::KEY, $result['target']);
        $order = $this->inTenant(fn () => TestDocuments::find(TestOrderType::KEY, $result['document_id']));
        $this->assertSame(['amount' => $this->kes(250000), 'supplier' => $supplier], $order['values']);
        $this->assertSame($this->branchA->id, $order['scope']->branchId, 'in the task\'s scope');
    }

    public function test_set_credit_hold_goes_through_the_types_capability(): void
    {
        $this->saveRule(['type' => 'field_changed', 'field' => 'status', 'to' => 'closed'], [['type' => 'set_credit_hold', 'hold' => true, 'reason' => 'Invoice 30 days overdue']]);
        $id = $this->quietTask();

        $this->changeTask($id, ['status' => 'closed']);

        $this->assertTrue($this->taskValues($id)['on_hold']);
        $this->assertSame('Invoice 30 days overdue', $this->taskValues($id)['note']);
    }

    public function test_lifting_a_credit_hold_needs_the_types_release_permission(): void
    {
        // May change tasks but not lift holds (core.party.archive for the test type).
        $clerk = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Credit clerk', ['core.automation.view', 'core.automation.edit', 'core.party.view', 'core.party.edit']), Scope::tenant());

            return $user;
        });
        $body = fn (bool $hold) => [
            'name' => 'Hold', 'document_type' => 'core.test_task', 'trigger' => ['type' => 'record_created'],
            'actions' => [['type' => 'set_credit_hold', 'hold' => $hold, 'reason' => 'Paid']],
        ];

        $this->postJson('/api/v1/automation-rules', $body(false), $this->headersFor($clerk))->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $this->postJson('/api/v1/automation-rules', $body(true), $this->headersFor($clerk))->assertCreated();
        $this->postJson('/api/v1/automation-rules', $body(false), $this->headersFor())->assertCreated();

        // A releasing rule acting as someone without the permission never lifts the hold.
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'set_credit_hold', 'hold' => false, 'reason' => 'Paid']], [], $clerk);
        $id = $this->createTask(['on_hold' => true]);
        $this->assertTrue($this->taskValues($id)['on_hold']);
        $this->assertSame('run_as_unavailable', $this->runs($rule)->sole()->error_code);
    }

    public function test_a_failing_action_undoes_the_earlier_ones_and_skips_the_rest(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [
            $this->notifyOwner(),
            ['type' => 'change_stage', 'mode' => 'move'],
            ['type' => 'update_field', 'field' => 'urgent', 'value' => true],
        ]);

        $id = $this->createTask();

        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::FAILED, $run->outcome);
        $this->assertSame(['rolled_back', 'failed', 'not_run'], array_column($run->actions, 'status'));
        $this->assertArrayNotHasKey('urgent', $this->taskValues($id));
        $this->assertSame(0, $this->inTenant(fn () => InAppNotification::query()->where('event_type', 'core.automation.notify')->count()), 'the notification was undone');
    }

    public function test_actions_act_as_the_rules_last_editor(): void
    {
        $this->publishFlow(Graphs::linear(['review', 'check'], ['review' => ['exit_roles' => ['template:accountant']]]));
        $accountant = $this->userWith('accountant', Scope::tenant());
        $this->inTenant(fn () => $this->assign($accountant, $this->roles->get('admin'), Scope::tenant()));
        $rule = $this->inTenant(fn () => app(Rules::class)->create([
            'name' => 'Move on', 'document_type' => TestRequestType::KEY, 'enabled' => true,
            'trigger' => ['type' => 'stage_entered', 'stage' => 'review'],
            'actions' => [['type' => 'change_stage', 'mode' => 'move']],
        ], $this->owner));
        // The owner holds every permission but not the accountant role the stage asks for.

        $first = $this->start($this->document());
        $this->assertSame(['review'], $this->at($first));
        $this->assertSame(AutomationRun::FAILED, $this->runs($rule)->sole()->outcome);

        $this->inTenant(function () use ($rule, $accountant) {
            $rule->forceFill(['updated_by' => $accountant->id])->save();
        });
        $second = $this->start($this->document());
        $this->assertSame(['check'], $this->at($second), 'acting as the accountant, the move is allowed');
    }
}
