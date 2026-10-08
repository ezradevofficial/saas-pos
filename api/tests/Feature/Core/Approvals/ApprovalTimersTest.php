<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAction;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Models\DocumentWorkflow;
use Carbon\CarbonImmutable;
use App\Core\Approvals\Jobs\ProcessApprovalTimers;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * APR-05: reminders, escalation and the final timeout, in business time
 * (Monday to Friday 08:00-17:00 Nairobi by default), run by the
 * scheduled command; reruns never remind or escalate twice. The clock is
 * frozen: Wednesday 2026-10-07 10:00 Nairobi (07:00Z).
 */
class ApprovalTimersTest extends TestCase
{
    use BuildsApprovals, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpApprovals();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function runAt(string $utc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc));
        // What the scheduled command queues per tenant (ApprovalTimersCommandTest covers the command).
        ProcessApprovalTimers::dispatchSync($this->owner->tenant_id, $utc);
    }

    private function notices(string $event, string $userId): int
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('user_id', $userId)->where('event_type', $event)->count());
    }

    /** @return list<string> */
    private function history(ApprovalRequest $request): array
    {
        return $this->inTenant(fn () => ApprovalAction::query()->where('request_id', $request->id)->orderBy('occurred_at')->pluck('type')->all());
    }

    public function test_reminders_go_out_at_their_business_time_offsets_once(): void
    {
        $approval = $this->submit($this->approvalGraph([], ['reminders' => [['amount' => 2, 'unit' => 'business_hours'], ['amount' => 1, 'unit' => 'business_days']]]));
        // 10:00 + 2 business hours = 12:00 Nairobi (09:00Z).
        $this->assertSame('2026-10-07T09:00:00Z', $this->fresh($approval)->next_reminder_at->toIso8601ZuluString());

        $this->runAt('2026-10-07T08:59:00Z');
        $this->assertSame(0, $this->notices('core.approval.reminder', $this->managerA->id));

        $this->runAt('2026-10-07T09:00:00Z');
        $this->runAt('2026-10-07T09:00:00Z');
        $this->runAt('2026-10-07T12:00:00Z');
        $this->assertSame(1, $this->notices('core.approval.reminder', $this->managerA->id));

        // The second offset: Thursday 10:00 Nairobi.
        $this->assertSame('2026-10-08T07:00:00Z', $this->fresh($approval)->next_reminder_at->toIso8601ZuluString());
        $this->runAt('2026-10-08T07:00:00Z');
        $this->runAt('2026-10-08T07:05:00Z');
        $this->assertSame(2, $this->notices('core.approval.reminder', $this->managerA->id));
        $this->assertNull($this->fresh($approval)->next_reminder_at);
    }

    public function test_escalation_goes_to_the_next_levels_manager_in_business_hours_once_and_they_decide_alone(): void
    {
        $companyAdmin = $this->person('admin', Scope::company($this->acme->id), 'Carl Company Admin');
        $second = $this->person('branch_manager', Scope::branch($this->branchA->id), 'Mo Manager A2');
        $approval = $this->submit($this->approvalGraph(['mode' => 'all'], ['escalation' => ['after' => ['amount' => 8, 'unit' => 'business_hours'], 'to' => ['type' => 'next_level']]]));

        // 10:00 Wednesday + 8 business hours (7 today, 1 tomorrow) = Thursday 09:00 Nairobi.
        $this->assertSame('2026-10-08T06:00:00Z', $this->fresh($approval)->escalate_at->toIso8601ZuluString());
        $item = $this->getJson($this->approvalUrl($approval), $this->headersFor($this->managerA))->json('data');
        $this->assertSame(['at' => '2026-10-08T06:00:00Z', 'to' => 'the next level’s manager'], $item['escalation']);

        $this->runAt('2026-10-07T14:00:00Z'); // 17:00 Wednesday: not yet.
        $this->assertSame(0, $this->notices('core.approval.escalated', $companyAdmin->id));

        $this->runAt('2026-10-08T06:00:00Z');
        $this->runAt('2026-10-08T06:01:00Z');
        $this->assertSame(1, $this->notices('core.approval.escalated', $companyAdmin->id));
        $this->assertSame(1, $this->fresh($approval)->escalation_level);
        $this->assertEqualsCanonicalizing([$this->managerA->id, $second->id, $companyAdmin->id], $this->pendingApprovers($approval));

        // The escalated approver decides alone, even in "all" mode.
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($companyAdmin))->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_the_final_timeout_approves_automatically_as_the_system(): void
    {
        $approval = $this->submit($this->approvalGraph([], ['due' => ['amount' => 1, 'unit' => 'business_days'], 'escalation' => ['final' => 'approve']]));
        $this->assertSame('2026-10-08T07:00:00Z', $this->fresh($approval)->escalate_at->toIso8601ZuluString());

        $this->runAt('2026-10-08T06:59:00Z');
        $this->assertSame('pending', $this->fresh($approval)->status);

        $this->runAt('2026-10-08T07:00:00Z');
        $this->runAt('2026-10-08T07:10:00Z');
        $decided = $this->fresh($approval);
        $this->assertSame(['approved', true], [$decided->status, $decided->auto_decided]);
        $this->assertSame('approved', $this->inTenant(fn () => DocumentWorkflow::query()->find($approval->workflow_id))->outcome);
        $this->assertSame(1, count(array_keys($this->history($approval), 'auto_approved')));
        $this->assertNull($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.approval.auto_decide')->sole())->user_id);
        $this->assertSame(1, $this->notices('core.approval.decided', $this->requester->id));
    }

    public function test_after_the_last_escalation_the_final_timeout_rejects(): void
    {
        $approval = $this->submit($this->approvalGraph([], ['escalation' => [
            'after' => ['amount' => 1, 'unit' => 'hours'], 'to' => ['type' => 'user', 'user_id' => $this->accountant->id], 'final' => 'reject',
        ]]));

        $this->runAt('2026-10-07T08:00:00Z');
        $this->assertContains($this->accountant->id, $this->pendingApprovers($approval));
        $this->assertSame('pending', $this->fresh($approval)->status);

        $this->runAt('2026-10-07T09:00:00Z');
        $this->assertSame(['rejected', true], [$this->fresh($approval)->status, $this->fresh($approval)->auto_decided]);
        $this->assertSame([], $this->pendingApprovers($approval));
        $this->assertSame(['requested', 'escalated', 'auto_rejected'], array_values(array_intersect($this->history($approval), ['requested', 'escalated', 'auto_rejected'])));
    }

    public function test_business_time_skips_the_weekend(): void
    {
        // Friday 16:00 Nairobi + 2 business hours = Monday 09:00.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09T13:00:00Z'));
        $approval = $this->submit($this->approvalGraph([], ['escalation' => ['after' => ['amount' => 2, 'unit' => 'business_hours'], 'to' => 'next_level']]));
        $this->assertSame('2026-10-12T06:00:00Z', $this->fresh($approval)->escalate_at->toIso8601ZuluString());

        $this->runAt('2026-10-10T09:00:00Z');
        $this->assertSame(0, $this->fresh($approval)->escalation_level);
    }

    public function test_without_a_target_or_final_the_timers_stop(): void
    {
        $approval = $this->submit($this->approvalGraph([], ['escalation' => ['after' => ['amount' => 1, 'unit' => 'hours'], 'to' => ['type' => 'role', 'role' => 'template:waiter']]]));

        $this->runAt('2026-10-07T08:00:00Z');
        $this->runAt('2026-10-07T09:00:00Z');
        $this->assertNull($this->fresh($approval)->escalate_at);
        $this->assertSame('pending', $this->fresh($approval)->status);
        $this->assertSame(1, count(array_keys($this->history($approval), 'escalation_exhausted')));
    }
}
