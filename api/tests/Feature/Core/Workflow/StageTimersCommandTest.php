<?php

namespace Tests\Feature\Core\Workflow;

use App\Core\Workflow\Jobs\ProcessStageTimers;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsWorkflows;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\Support\Workflow\Graphs;
use Tests\TestCase;

/**
 * WF-09: `workflow:process-stage-timers` queues one ProcessStageTimers job
 * per tenant with a stage timer due, every five minutes, on one server,
 * without overlapping. Tenant ids come from a security-definer function on
 * the runtime connection (DueTenants), so the test runs with the owner
 * connection unusable (ADR 002) and inside the usual test transaction.
 */
class StageTimersCommandTest extends TestCase
{
    use BuildsWorkflows, RefreshTenantDatabase, WithoutOwnerConnection;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_command_queues_a_run_per_tenant_with_timers_due(): void
    {
        Mail::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07T07:00:00Z'));
        $this->setUpWorkflows();
        $this->publishFlow(Graphs::linear(['review'], ['review' => ['reminders' => [['amount' => 1, 'unit' => 'hours']]]]));
        $this->start($this->document());
        // A tenant with nothing due gets no run.
        $this->otherTenant();

        $this->withoutOwnerConnection();
        Bus::fake();
        $this->artisan('workflow:process-stage-timers', ['--at' => '2026-10-07T07:30:00Z'])->assertSuccessful();
        Bus::assertNotDispatched(ProcessStageTimers::class);

        $this->artisan('workflow:process-stage-timers', ['--at' => '2026-10-07T08:00:00Z'])->assertSuccessful();
        Bus::assertDispatchedTimes(ProcessStageTimers::class, 1);
        Bus::assertDispatched(ProcessStageTimers::class, fn (ProcessStageTimers $job) => $job->queue === 'notifications');
        Bus::assertDispatched(ProcessStageTimers::class, fn (ProcessStageTimers $job) => $job->tenantId === $this->owner->tenant_id
            && $job->at === '2026-10-07T08:00:00+00:00');
        $this->assertSame($this->owner->tenant_id, (new ProcessStageTimers($this->owner->tenant_id, 'x'))->uniqueId());

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'workflow:process-stage-timers'));
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->onOneServer);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
