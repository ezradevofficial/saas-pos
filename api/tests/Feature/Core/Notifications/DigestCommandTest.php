<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Notifications\Jobs\SendDigests;
use App\Core\Notifications\Models\NotificationPreference;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;

/**
 * NOT-05: `notifications:send-digests` queues one SendDigests job per
 * tenant with emails held for a digest, and runs hourly. Tenant ids come
 * from a security-definer function on the runtime connection, so the test
 * runs with the owner connection unusable (ADR 002).
 */
class DigestCommandTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase, WithoutOwnerConnection;

    public function test_the_hourly_command_queues_a_digest_run_per_tenant_with_held_emails(): void
    {
        Mail::fake();
        $this->setUpOrganisation();
        $this->inTenant(fn () => NotificationPreference::create(['user_id' => $this->owner->id, 'event_type' => 'core.notification.test', 'digest' => 'daily']));
        $this->sendTest([$this->owner]);
        // A tenant with nothing held gets no run.
        $this->otherTenant();

        $this->withoutOwnerConnection();
        Bus::fake();
        $this->artisan('notifications:send-digests', ['--at' => '2026-10-09T04:30:00Z'])->assertSuccessful();

        Bus::assertDispatchedTimes(SendDigests::class, 1);
        Bus::assertDispatched(SendDigests::class, fn (SendDigests $job) => $job->tenantId === $this->owner->tenant_id
            && $job->at === '2026-10-09T04:30:00+00:00');

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'notifications:send-digests'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->onOneServer);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
