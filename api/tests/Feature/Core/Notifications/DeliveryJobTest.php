<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Notifications\Jobs\SendDelivery;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NOT-06: retries with backoff and a final failed state; NOT-01 fake
// drivers; TEN-01 jobs run in their delivery's tenant only.
class DeliveryJobTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
    }

    private function smsOnly(): void
    {
        $this->inTenant(fn () => NotificationPreference::create([
            'user_id' => $this->owner->id, 'event_type' => 'core.notification.test',
            'channels' => ['in_app' => false, 'email' => false, 'sms' => true],
        ]));
        $this->inTenant(fn () => $this->owner->forceFill(['phone' => '+254722000111', 'phone_verified_at' => now()])->save());
    }

    public function test_a_failed_attempt_is_retried_and_then_delivered(): void
    {
        $this->smsOnly();
        $this->fakeDriver('sms')->failNext(2, 'Gateway timeout');

        $this->sendTest([$this->owner]);

        $this->inTenant(function () {
            $delivery = NotificationDelivery::sole();
            $this->assertSame('delivered', $delivery->status);
            $this->assertSame(3, $delivery->attempts);
            $this->assertNull($delivery->error, 'the error clears once it goes through');
            $this->assertNull($delivery->next_attempt_at);
            $this->assertStringStartsWith('fake-', $delivery->provider_message_id);
            $this->assertNotNull($delivery->delivered_at);
        });
        $this->assertCount(1, $this->fakeDriver('sms')->sent);
    }

    public function test_after_the_last_attempt_the_delivery_is_failed_with_its_error(): void
    {
        $this->smsOnly();
        $this->fakeDriver('sms')->failNext(5, 'Gateway timeout');

        $this->sendTest([$this->owner]);

        $this->inTenant(function () {
            $delivery = NotificationDelivery::sole();
            $this->assertSame('failed', $delivery->status);
            $this->assertSame(3, $delivery->attempts);
            $this->assertSame('RuntimeException: Gateway timeout', $delivery->error);
            $this->assertNotNull($delivery->failed_at);
            $this->assertNull($delivery->sent_at);
        });
        $this->assertCount(0, $this->fakeDriver('sms')->sent);
    }

    public function test_retries_wait_for_the_backoff(): void
    {
        $this->smsOnly();
        $this->fakeDriver('sms')->failNext(1);
        Bus::fake([SendDelivery::class]);

        $this->sendTest([$this->owner]);
        $first = null;
        Bus::assertDispatched(SendDelivery::class, function (SendDelivery $job) use (&$first) {
            $first = $job;

            return true;
        });

        // The worker runs the first attempt: it fails and queues the next in 60 seconds.
        $this->runJob($first);

        Bus::assertDispatched(SendDelivery::class, fn (SendDelivery $job) => $job->deliveryId === $first->deliveryId && $job->delay === 60);
        $this->inTenant(function () {
            $delivery = NotificationDelivery::sole();
            $this->assertSame(['queued', 1], [$delivery->status, $delivery->attempts]);
            $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $delivery->next_attempt_at->timestamp, 2);
        });
    }

    public function test_a_failing_mailer_is_retried_and_recorded(): void
    {
        Mail::shouldReceive('to')->times(3)->andThrow(new RuntimeException('SMTP down'));

        $this->sendTest([$this->owner]);

        $this->inTenant(function () {
            $email = NotificationDelivery::where('channel', 'email')->sole();
            $this->assertSame(['failed', 3, 'RuntimeException: SMTP down'], [$email->status, $email->attempts, $email->error]);
            $this->assertSame('delivered', NotificationDelivery::where('channel', 'in_app')->sole()->status);
        });
    }

    public function test_a_job_runs_in_its_own_tenant_whatever_context_the_worker_had(): void
    {
        $other = $this->otherTenant();
        Bus::fake([SendDelivery::class]);
        $this->sendTest([$this->owner]);
        $job = null;
        Bus::assertDispatched(SendDelivery::class, function (SendDelivery $dispatched) use (&$job) {
            $job = $dispatched;

            return true;
        });
        $this->assertSame($this->owner->tenant_id, $job->tenantId);

        // A worker left in another tenant's context: the job still sees its own delivery.
        app(TenantContext::class)->set($other['user']->tenant_id);
        $this->runJob($job);
        $this->assertSame($other['user']->tenant_id, app(TenantContext::class)->id(), 'the previous context comes back');
        $this->inTenant(fn () => $this->assertSame('sent', NotificationDelivery::findOrFail($job->deliveryId)->status));
        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_a_job_naming_another_tenants_delivery_finds_nothing_and_sends_nothing(): void
    {
        $other = $this->otherTenant();
        Bus::fake([SendDelivery::class]);
        $this->sendTest([$this->owner]);
        $theirs = $this->inTenant(fn () => NotificationDelivery::where('channel', 'email')->sole());

        $this->runJob(new SendDelivery($other['user']->tenant_id, $theirs->id));

        Mail::assertNothingSent();
        $this->inTenant(fn () => $this->assertSame(['queued', 0], [$theirs->fresh()->status, $theirs->fresh()->attempts]));
    }

    public function test_a_delivery_already_sent_is_not_sent_again(): void
    {
        $this->sendTest([$this->owner]);
        $delivery = $this->inTenant(fn () => NotificationDelivery::where('channel', 'email')->sole());
        Mail::assertSent(NotificationMail::class, 1);

        $this->runJob(new SendDelivery($this->owner->tenant_id, $delivery->id));

        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_a_driver_removed_after_queueing_skips_the_delivery(): void
    {
        $this->smsOnly();
        Bus::fake([SendDelivery::class]);
        $this->sendTest([$this->owner]);
        $delivery = $this->inTenant(fn () => NotificationDelivery::sole());

        config(['notifications.drivers.sms' => 'none']);
        $this->runJob(new SendDelivery($this->owner->tenant_id, $delivery->id));

        $this->inTenant(fn () => $this->assertSame(['skipped', 'channel_unavailable'], [$delivery->fresh()->status, $delivery->fresh()->reason]));
    }

    public function test_a_crashed_job_marks_its_delivery_failed_in_its_tenant(): void
    {
        Bus::fake([SendDelivery::class]);
        $this->sendTest([$this->owner]);
        $delivery = $this->inTenant(fn () => NotificationDelivery::where('channel', 'email')->sole());
        app(TenantContext::class)->set(null);

        (new SendDelivery($this->owner->tenant_id, $delivery->id))->failed(new RuntimeException('Worker timed out'));

        $this->inTenant(fn () => $this->assertSame(['failed', 'Worker timed out'], [$delivery->fresh()->status, $delivery->fresh()->error]));
        $this->assertNull(app(TenantContext::class)->id());
    }

    /** Run a job as the worker does: through its middleware (TenantAware). */
    private function runJob(SendDelivery $job): void
    {
        $this->assertEquals([new TenantAware], $job->middleware());
        (new TenantAware)->handle($job, fn (SendDelivery $job) => app()->call([$job, 'handle']));
    }
}
