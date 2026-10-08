<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Approvals\Jobs\ProcessApprovalTimers;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * APR-05: `approvals:process-timers` queues one ProcessApprovalTimers job
 * per tenant with a reminder or escalation due, every five minutes, on one
 * server, without overlapping. The command reads tenant ids as the schema
 * owner, which cannot see uncommitted rows, so this test commits and the
 * next test migrates afresh.
 */
class ApprovalTimersCommandTest extends TestCase
{
    use BuildsApprovals, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_the_command_queues_a_run_per_tenant_with_timers_due(): void
    {
        Mail::fake();
        $this->setUpApprovals();
        $this->submit($this->approvalGraph([], ['reminders' => [['amount' => 1, 'unit' => 'hours']]]));
        // A tenant with nothing due gets no run.
        $this->otherTenant();

        Bus::fake();
        $this->artisan('approvals:process-timers', ['--at' => '2026-10-07T07:30:00Z'])->assertSuccessful();
        Bus::assertNotDispatched(ProcessApprovalTimers::class);

        $this->artisan('approvals:process-timers', ['--at' => '2026-10-07T08:00:00Z'])->assertSuccessful();
        Bus::assertDispatchedTimes(ProcessApprovalTimers::class, 1);
        Bus::assertDispatched(ProcessApprovalTimers::class, fn (ProcessApprovalTimers $job) => $job->tenantId === $this->owner->tenant_id
            && $job->at === '2026-10-07T08:00:00+00:00');

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'approvals:process-timers'));
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->onOneServer);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
