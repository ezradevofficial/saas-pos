<?php

namespace Tests\Feature\Core\Support;

use App\Core\Approvals\Jobs\ProcessApprovalTimers;
use App\Core\Automation\Jobs\RetryThrottledRun;
use App\Core\Automation\Jobs\RunAutomationRule;
use App\Core\Automation\Jobs\ScanTimedTriggers;
use App\Core\Automation\Jobs\SendWebhookDelivery;
use App\Core\Imports\Jobs\RunImport;
use App\Core\MasterData\CreditLimits\ApplyCreditLimitChange;
use App\Core\MasterData\CreditLimits\Listeners\SettleCreditLimitChange;
use App\Core\Notifications\Jobs\SendDelivery;
use App\Core\Notifications\Jobs\SendDigests;
use App\Core\Webhooks\Jobs\FanOutWebhookEvent;
use App\Core\Webhooks\Jobs\SendWebhook;
use App\Core\Webhooks\Jobs\SweepWebhooks;
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
        $this->assertEqualsCanonicalizing(['default', 'notifications', 'automation', 'payments', 'fiscal', 'exports', 'imports', 'webhooks'], $queues);

        $webhook = config('automation.webhook_timeout') + config('automation.dns_timeout');

        foreach ($supervisors as $name => $supervisor) {
            // Each supervisor pops from a redis connection whose retry_after outlasts its timeout;
            // long jobs (tenant exports, EXP-02) have their own connection with a longer window.
            $connection = $supervisor['connection'];
            $this->assertSame('redis', config("queue.connections.{$connection}.driver"), $name);
            $this->assertGreaterThan($webhook, $supervisor['timeout'], "{$name} outlasts a webhook");
            $this->assertLessThan(config("queue.connections.{$connection}.retry_after"), $supervisor['timeout'], "{$name} ends before redis hands the job to another worker");
        }

        foreach (['local', '*'] as $environment) {
            $this->assertSame(array_keys($supervisors), array_keys(config("horizon.environments.{$environment}")));
        }
    }

    /** ADR 025: the 1 vCPU starting host runs five workers, every queue still worked and long jobs still on their own connections. */
    public function test_the_single_host_profile_keeps_every_queue_with_few_workers(): void
    {
        $_SERVER['HORIZON_PROFILE'] = $_ENV['HORIZON_PROFILE'] = 'single-host';
        try {
            $horizon = require config_path('horizon.php');
        } finally {
            unset($_SERVER['HORIZON_PROFILE'], $_ENV['HORIZON_PROFILE']);
        }

        $supervisors = $horizon['defaults'];
        $queues = collect($supervisors)->flatMap(fn ($s) => $s['queue'])->all();
        $this->assertEqualsCanonicalizing(['default', 'notifications', 'automation', 'payments', 'fiscal', 'exports', 'imports', 'webhooks'], $queues);
        $this->assertCount(count(array_unique($queues)), $queues, 'no queue is worked by two supervisors');

        // Payments and fiscal are taken first; one pool, no balancing across queues.
        $this->assertSame(['payments', 'fiscal', 'notifications', 'default', 'automation'], $supervisors['supervisor-default']['queue']);
        $this->assertFalse($supervisors['supervisor-default']['balance']);

        foreach (['supervisor-exports' => 'redis-exports', 'supervisor-imports' => 'redis-imports', 'supervisor-webhooks' => 'redis-webhooks'] as $name => $connection) {
            $this->assertSame($connection, $supervisors[$name]['connection'], $name);
        }
        foreach ($supervisors as $name => $supervisor) {
            $this->assertLessThan(config("queue.connections.{$supervisor['connection']}.retry_after"), $supervisor['timeout'], $name);
        }

        foreach (['local', '*'] as $environment) {
            $plan = $horizon['environments'][$environment];
            $this->assertSame(array_keys($supervisors), array_keys($plan));
            $this->assertSame(5, array_sum(array_column($plan, 'maxProcesses')), "{$environment}: five worker processes in all");
        }

        // Without the profile nothing changes.
        $this->assertCount(8, config('horizon.defaults'));
    }

    public function test_imports_run_on_their_own_queue_and_connection(): void
    {
        // IMP-03: an hour-long import is reserved for longer than it may run.
        $this->assertSame('imports', config('imports.queue'));
        $this->assertSame('redis-imports', config('horizon.defaults.supervisor-imports.connection'));
        $this->assertGreaterThan(config('imports.timeout'), config('queue.connections.redis-imports.retry_after'));

        Queue::fake();
        RunImport::dispatch((string) Str::uuid7(), (string) Str::uuid7(), RunImport::IMPORT, (string) Str::uuid7());
        Queue::assertPushedOn('imports', RunImport::class);
        Queue::assertPushed(RunImport::class, fn (RunImport $job) => $job->timeout === config('imports.timeout') && $job->tries === 1);
    }

    public function test_webhooks_run_on_their_own_queue_supervisor_and_connection(): void
    {
        // HOOK-01: a slow receiver never delays notifications, automation or fiscal work.
        $this->assertSame('webhooks', config('webhooks.queue'));
        $this->assertSame('redis-webhooks', config('horizon.defaults.supervisor-webhooks.connection'));
        $this->assertSame(['webhooks'], config('horizon.defaults.supervisor-webhooks.queue'));
        $this->assertSame('webhooks', config('queue.connections.redis-webhooks.queue'));
        $this->assertGreaterThan(config('webhooks.timeout') + config('automation.dns_timeout'), config('horizon.defaults.supervisor-webhooks.timeout'));

        foreach (config('horizon.defaults') as $name => $supervisor) {
            if ($name !== 'supervisor-webhooks') {
                $this->assertNotContains('webhooks', $supervisor['queue'], "{$name} never works the webhooks queue");
            }
        }

        Queue::fake();
        $tenant = (string) Str::uuid7();
        FanOutWebhookEvent::dispatch($tenant, 'sale.completed.v1', (string) Str::uuid7(), now()->toIso8601ZuluString(), ['data' => []]);
        SendWebhook::dispatch($tenant, (string) Str::uuid7());
        SweepWebhooks::dispatch($tenant, now()->toIso8601String());

        foreach ([FanOutWebhookEvent::class, SendWebhook::class, SweepWebhooks::class] as $job) {
            Queue::assertPushedOn('webhooks', $job);
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
