<?php

namespace Tests\Feature\Core\Support;

use App\Core\Approvals\Jobs\ProcessApprovalTimers;
use App\Core\Automation\Jobs\RetryThrottledRun;
use App\Core\Automation\Jobs\RunAutomationRule;
use App\Core\Automation\Jobs\ScanTimedTriggers;
use App\Core\Automation\Jobs\SendWebhookDelivery;
use App\Core\MasterData\CreditLimits\ApplyCreditLimitChange;
use App\Core\MasterData\CreditLimits\Listeners\SettleCreditLimitChange;
use App\Core\Notifications\Jobs\SendDelivery;
use App\Core\Notifications\Jobs\SendDigests;
use App\Core\Workflow\Jobs\ProcessStageTimers;
use App\Core\Workflow\Listeners\SendWorkflowNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;
use Tests\TestCase;

/**
 * NFR: notification and automation jobs run on their own queues, each
 * with its own Horizon supervisor, so a burst on one never starves the
 * other. The Horizon dashboard is open only in `local`.
 */
class QueuesTest extends TestCase
{
    public function test_jobs_land_on_their_queues(): void
    {
        $this->assertSame('notifications', config('notifications.queue'));
        $this->assertSame('automation', config('automation.queue'));

        Queue::fake();
        $tenant = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        $at = now()->toIso8601String();

        SendDelivery::dispatch($tenant, $id);
        SendDigests::dispatch($tenant, $at);
        ProcessApprovalTimers::dispatch($tenant, $at);
        RunAutomationRule::dispatch($tenant, $id);
        ScanTimedTriggers::dispatch($tenant, ScanTimedTriggers::SCHEDULES, $at);
        SendWebhookDelivery::dispatch($tenant, $id);
        ProcessStageTimers::dispatch($tenant, $at);
        RetryThrottledRun::dispatch($tenant, $id);
        ApplyCreditLimitChange::dispatch($tenant, $id);

        Queue::assertPushedOn('default', ApplyCreditLimitChange::class);
        $this->assertSame('default', app(SettleCreditLimitChange::class)->viaQueue());
        $this->assertSame('notifications', app(SendWorkflowNotification::class)->viaQueue());

        foreach ([SendDelivery::class, SendDigests::class, ProcessApprovalTimers::class, ProcessStageTimers::class] as $job) {
            Queue::assertPushedOn('notifications', $job);
        }
        foreach ([RunAutomationRule::class, ScanTimedTriggers::class, SendWebhookDelivery::class, RetryThrottledRun::class] as $job) {
            Queue::assertPushedOn('automation', $job);
        }
    }

    public function test_horizon_has_a_supervisor_per_queue_with_timeouts_above_the_webhook_timeout(): void
    {
        $supervisors = config('horizon.defaults');
        $queues = collect($supervisors)->flatMap(fn ($s) => $s['queue'])->all();
        $this->assertEqualsCanonicalizing(['default', 'notifications', 'automation'], $queues);

        $webhook = config('automation.webhook_timeout') + config('automation.dns_timeout');
        $retryAfter = config('queue.connections.redis.retry_after');

        foreach ($supervisors as $name => $supervisor) {
            $this->assertSame('redis', $supervisor['connection'], $name);
            $this->assertGreaterThan($webhook, $supervisor['timeout'], "{$name} outlasts a webhook");
            $this->assertLessThan($retryAfter, $supervisor['timeout'], "{$name} ends before redis hands the job to another worker");
        }

        foreach (['local', '*'] as $environment) {
            $this->assertSame(array_keys($supervisors), array_keys(config("horizon.environments.{$environment}")));
        }
    }

    public function test_the_horizon_dashboard_is_refused_outside_local(): void
    {
        $this->assertFalse(Horizon::check(request()));
        $this->get('/horizon')->assertForbidden();
        $this->get('/horizon/api/jobs/recent')->assertForbidden();

        $this->app['env'] = 'local';
        $this->assertTrue(Horizon::check(request()));
    }
}
