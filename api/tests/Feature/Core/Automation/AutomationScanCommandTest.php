<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Automation\Jobs\ScanTimedTriggers;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * AUTO-01: `automation:scan schedules` (every minute) and `automation:scan
 * dates` (hourly) queue one ScanTimedTriggers job per tenant with live
 * rules of that kind (schedules only when one is due). The command lists
 * tenants as the schema owner, which cannot see uncommitted rows, so this
 * test commits and the next test migrates afresh.
 */
class AutomationScanCommandTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_the_scans_queue_a_job_per_tenant_with_rules_of_that_kind(): void
    {
        CarbonImmutable::setTestNow('2026-10-08T03:00:00Z');
        $this->setUpAutomation();
        $this->saveRule(['type' => 'schedule', 'every' => 'day', 'time' => '08:00'], [$this->notifyOwner('Daily', 'Count.')]); // due 05:00 UTC
        $this->saveRule(['type' => 'schedule', 'every' => 'day', 'time' => '09:00'], [$this->notifyOwner('Off', 'Count.')], ['enabled' => false]);
        $this->saveRule(['type' => 'date', 'field' => 'due_on', 'days' => 1, 'when' => 'before'], [$this->notifyOwner()]);
        $this->otherTenant(); // no rules: no scans

        Bus::fake();
        $this->artisan('automation:scan', ['kind' => 'schedules', '--at' => '2026-10-08T04:00:00Z'])->assertSuccessful();
        Bus::assertNotDispatched(ScanTimedTriggers::class);

        $this->artisan('automation:scan', ['kind' => 'schedules', '--at' => '2026-10-08T05:00:00Z'])->assertSuccessful();
        Bus::assertDispatchedTimes(ScanTimedTriggers::class, 1);
        Bus::assertDispatched(ScanTimedTriggers::class, fn (ScanTimedTriggers $job) => $job->tenantId === $this->owner->tenant_id
            && $job->kind === 'schedules' && $job->at === '2026-10-08T05:00:00+00:00');

        $this->artisan('automation:scan', ['kind' => 'dates', '--at' => '2026-10-08T05:00:00Z'])->assertSuccessful();
        Bus::assertDispatched(ScanTimedTriggers::class, fn (ScanTimedTriggers $job) => $job->kind === 'dates' && $job->tenantId === $this->owner->tenant_id);
        Bus::assertDispatchedTimes(ScanTimedTriggers::class, 2);

        $this->artisan('automation:scan', ['kind' => 'weekly'])->assertExitCode(2);

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'automation:scan'))->values();
        $this->assertSame(['* * * * *', '0 * * * *'], $events->pluck('expression')->all());
        $this->assertTrue($events->every(fn ($e) => $e->onOneServer && $e->withoutOverlapping));
    }
}
