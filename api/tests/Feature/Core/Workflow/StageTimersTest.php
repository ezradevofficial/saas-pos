<?php

namespace Tests\Feature\Core\Workflow;

use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Jobs\ProcessStageTimers;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\DocumentWorkflowEvent;
use App\Core\Workflow\Models\DocumentWorkflowToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsWorkflows;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * WF-09: plain stages' reminders, overdue notices and escalation, in the
 * company's business time (Nairobi, Monday to Friday 08:00-17:00). The
 * document enters "Review" on Wednesday 2026-10-07 at 10:00 Nairobi.
 */
class StageTimersTest extends TestCase
{
    use BuildsWorkflows, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07T07:00:00Z'));
        $this->setUpWorkflows();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function graph(array $review): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'review', 'type' => 'stage', 'name' => 'Review', ...$review],
                ['id' => 'end', 'type' => 'end', 'outcome' => 'completed'],
            ],
            'edges' => [['from' => 'start', 'to' => 'review'], ['from' => 'review', 'to' => 'end']],
        ];
    }

    private function runAt(string $at): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($at));
        ProcessStageTimers::dispatchSync($this->owner->tenant_id, $at);
    }

    /** @return list<string> "event_type user_id" of every in-app notice so far */
    private function sent(): array
    {
        return $this->inTenant(fn () => InAppNotification::query()->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($n) => $n->event_type.' '.$n->user_id)->all());
    }

    private function token(DocumentWorkflow $workflow): DocumentWorkflowToken
    {
        return $this->inTenant(fn () => DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->where('node_id', 'review')->sole());
    }

    public function test_reminders_overdue_and_escalation_go_out_once_each_in_business_time(): void
    {
        $holder = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->userWith('accountant', Scope::branch($this->branchB->id)); // not at branch A
        $managerA = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->publishFlow($this->graph([
            'exit_roles' => ['template:accountant'],
            // 8 open hours from Wed 10:00: Wed 10-17 (7h) and Thu 08-09 → Thu 09:00 (06:00Z).
            'reminders' => [['amount' => 8, 'unit' => 'business_hours']],
            // Thu 10:00 (07:00Z).
            'due' => ['amount' => 1, 'unit' => 'business_days'],
            // Fri 10:00 (07:00Z): branch managers at the document's place.
            'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:branch_manager']],
        ]));
        $id = $this->document();
        $workflow = $this->start($id);
        $this->assertSame('2026-10-08T06:00:00+00:00', $this->token($workflow)->next_timer_at->toIso8601String());

        // Wednesday evening (16:59 Nairobi): eight plain hours passed, not eight business hours.
        $this->runAt('2026-10-07T13:59:00Z');
        $this->assertSame([], $this->sent());

        $this->runAt('2026-10-08T06:00:00Z');
        $this->runAt('2026-10-08T06:05:00Z'); // a rerun sends nothing again
        $this->assertSame(["core.workflow.stage_reminder {$holder->id}"], $this->sent());

        $this->runAt('2026-10-08T07:00:00Z');
        $this->assertSame(["core.workflow.stage_reminder {$holder->id}", "core.workflow.stage_overdue {$holder->id}"], $this->sent());

        $this->runAt('2026-10-09T07:00:00Z');
        $this->runAt('2026-10-09T08:00:00Z');
        $this->assertSame([
            "core.workflow.stage_reminder {$holder->id}",
            "core.workflow.stage_overdue {$holder->id}",
            "core.workflow.stage_overdue {$managerA->id}",
        ], $this->sent());
        $this->assertNotContains("core.workflow.stage_overdue {$managerB->id}", $this->sent());

        $token = $this->token($workflow);
        $this->assertNull($token->next_timer_at);
        $this->assertSame(1, $token->reminders_sent);

        $this->inTenant(function () use ($workflow, $id, $holder) {
            $events = DocumentWorkflowEvent::query()->where('workflow_id', $workflow->id)->whereIn('type', ['reminded', 'overdue', 'escalated'])
                ->orderBy('occurred_at')->pluck('type')->all();
            $this->assertSame(['reminded', 'overdue', 'escalated'], $events);

            $reminder = InAppNotification::query()->where('event_type', 'core.workflow.stage_reminder')->sole();
            $this->assertSame('/document-workflows/'.TestRequestType::KEY.'/'.$id, $reminder->link);
            $this->assertStringContainsString('“Review”', $reminder->subject);
            $this->assertStringContainsString('2026-10-08 10:00', $reminder->body, 'the due time in the company’s time zone');
            $this->assertSame($holder->id, $reminder->user_id);
        });
    }

    public function test_a_stage_naming_no_roles_reminds_the_act_permission_holders_who_see_the_document(): void
    {
        $blind = $this->userWith('cashier', Scope::location($this->locationB->id));
        $this->publishFlow($this->graph([
            'reminders' => [['amount' => 1, 'unit' => 'hours']],
            'escalation' => ['after' => ['amount' => 2, 'unit' => 'hours'], 'to' => ['type' => 'user', 'user_id' => $blind->id]],
        ]));
        $workflow = $this->start($this->document());

        $this->runAt('2026-10-07T08:00:00Z');
        // The owner holds core.party.edit (the type's act permission) tenant-wide.
        $this->assertSame(["core.workflow.stage_reminder {$this->owner->id}"], $this->sent());

        // The escalation user cannot see a document of branch A: skipped, recorded.
        $this->runAt('2026-10-07T09:00:00Z');
        $this->assertSame(["core.workflow.stage_reminder {$this->owner->id}"], $this->sent());
        $escalated = $this->inTenant(fn () => DocumentWorkflowEvent::query()->where('workflow_id', $workflow->id)->where('type', 'escalated')->sole());
        $this->assertSame([], $escalated->data['users']);
    }

    public function test_each_of_several_reminders_goes_out_once_and_missed_ones_make_one(): void
    {
        $this->publishFlow($this->graph([
            'reminders' => [['amount' => 1, 'unit' => 'hours'], ['amount' => 2, 'unit' => 'hours'], ['amount' => 3, 'unit' => 'hours'], ['amount' => 1, 'unit' => 'days']],
        ]));
        $workflow = $this->start($this->document());
        $reminders = fn () => $this->inTenant(fn () => InAppNotification::query()->where('event_type', 'core.workflow.stage_reminder')->count());

        $this->runAt('2026-10-07T08:00:00Z');
        $this->assertSame(1, $reminders());
        $this->runAt('2026-10-07T09:00:00Z');
        $this->assertSame(2, $reminders());
        $this->assertSame('2026-10-07T10:00:00+00:00', $this->token($workflow)->next_timer_at->toIso8601String());

        // The third and fourth were both missed (the scheduler was down): one reminder covers them.
        $this->runAt('2026-10-08T08:00:00Z');
        $this->assertSame(3, $reminders());
        $this->assertSame(4, $this->token($workflow)->reminders_sent);
        $this->assertNull($this->token($workflow)->next_timer_at);
    }

    public function test_approvals_and_finished_stages_have_no_stage_timers(): void
    {
        $this->publishFlow($this->graph(['reminders' => [['amount' => 1, 'unit' => 'hours']]]));
        $workflow = $this->start($this->document());
        $this->inTenant(fn () => $this->engine()->move($workflow, $this->owner));

        $this->runAt('2026-10-07T09:00:00Z');
        $this->assertSame([], $this->sent());
    }
}
