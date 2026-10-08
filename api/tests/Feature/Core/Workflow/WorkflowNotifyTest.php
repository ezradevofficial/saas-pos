<?php

namespace Tests\Feature\Core\Workflow;

use App\Core\Notifications\Models\InAppNotification;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Definitions\GraphValidator;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Runtime\WorkflowBlocked;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsWorkflows;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * NOT-02 through flows: a `notify` step sends `core.workflow.notify`
 * through the Notifier to the roles holding it at the document's scope
 * (RBAC-04) and to named users, linking to the document's flow page.
 */
class WorkflowNotifyTest extends TestCase
{
    use BuildsWorkflows, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpWorkflows();
    }

    private function graph(array $config): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'review', 'type' => 'stage', 'name' => 'Review'],
                ['id' => 'tell', 'type' => 'action', 'name' => 'Tell procurement', 'action' => 'notify', 'config' => $config],
                ['id' => 'end', 'type' => 'end'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'review'], ['from' => 'review', 'to' => 'tell'], ['from' => 'tell', 'to' => 'end'],
            ],
        ];
    }

    public function test_the_step_notifies_role_holders_in_the_documents_scope_and_named_users(): void
    {
        $buyerA = $this->userWith('procurement_officer', Scope::branch($this->branchA->id));
        $buyerCompany = $this->userWith('procurement_officer', Scope::company($this->acme->id));
        $buyerB = $this->userWith('procurement_officer', Scope::branch($this->branchB->id));
        $named = $this->userWith('cashier', Scope::location($this->locationB->id));
        $this->publishFlow($this->graph(['to' => ['role:procurement_officer', "user:{$named->id}"], 'message' => 'Order the chairs.']));

        $id = $this->document();
        $workflow = $this->start($id);
        $this->assertSame(0, $this->inTenant(fn () => InAppNotification::query()->count()), 'nothing is sent before the step runs');

        $this->inTenant(fn () => $this->engine()->move($workflow, $this->owner));

        $this->inTenant(function () use ($buyerA, $buyerCompany, $buyerB, $named, $id) {
            $sent = InAppNotification::query()->where('event_type', 'core.workflow.notify')->get();
            $this->assertEqualsCanonicalizing([$buyerA->id, $buyerCompany->id, $named->id], $sent->pluck('user_id')->all());
            $this->assertNotContains($buyerB->id, $sent->pluck('user_id')->all());

            $mine = $sent->firstWhere('user_id', $buyerA->id);
            $this->assertSame('Workflows: Tell procurement', $mine->subject);
            $this->assertStringContainsString('Order the chairs.', $mine->body);
            $this->assertSame('/document-workflows/'.TestRequestType::KEY.'/'.$id, $mine->link);
            $this->assertSame(3, NotificationDelivery::query()->where('event_type', 'core.workflow.notify')->where('channel', 'email')->count());
        });
    }

    public function test_a_blocked_move_sends_nothing(): void
    {
        $graph = $this->graph(['to' => ['role:procurement_officer']]);
        $graph['nodes'][] = ['id' => 'after', 'type' => 'stage', 'name' => 'After', 'entry' => ['field' => 'urgent', 'op' => 'eq', 'value' => true]];
        $graph['edges'][2] = ['from' => 'tell', 'to' => 'after'];
        $graph['edges'][] = ['from' => 'after', 'to' => 'end'];
        $this->userWith('procurement_officer', Scope::company($this->acme->id));
        $this->publishFlow($graph);
        $workflow = $this->start($this->document(['urgent' => false]));

        try {
            $this->inTenant(fn () => $this->engine()->move($workflow, $this->owner));
            $this->fail('not blocked');
        } catch (WorkflowBlocked) {
        }

        $this->assertSame(0, $this->inTenant(fn () => InAppNotification::query()->count()));
    }

    public function test_recipients_are_validated_before_publishing(): void
    {
        $other = $this->otherTenant();
        $type = app(DocumentTypeRegistry::class)->get(TestRequestType::KEY);
        $problems = fn (array $config) => $this->inTenant(fn () => array_column(app(GraphValidator::class)->validate($this->graph($config), $type), 'message'));

        $this->assertSame([], $problems(['to' => ['role:accountant', 'role:'.$this->roles->get('cashier')->id, "user:{$this->owner->id}"]]));
        $this->assertSame([], $problems(['to' => 'role:accountant']));

        $this->assertSame(['“Tell procurement”: A notification needs recipients: a list of role:<role> and user:<user id>.'], $problems([]));
        $this->assertSame(['“Tell procurement”: A notification needs recipients: a list of role:<role> and user:<user id>.'], $problems(['to' => ['role:accountant', 5]]));
        $this->assertSame(['“Tell procurement”: The notification message must be text of at most 500 characters.'], $problems(['to' => 'role:accountant', 'message' => str_repeat('x', 501)]));

        $unknown = $problems(['to' => ['role:no_such_role', "user:{$other['user']->id}", 'everyone']]);
        $this->assertCount(1, $unknown);
        foreach (['role:no_such_role', "user:{$other['user']->id}", 'everyone'] as $entry) {
            $this->assertStringContainsString($entry, $unknown[0]);
        }
    }
}
