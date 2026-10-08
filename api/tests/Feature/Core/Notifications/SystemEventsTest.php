<?php

namespace Tests\Feature\Core\Notifications;

use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Jobs\SendDelivery;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ADR 009, NOT-02: system event types reach contacts that are not users
 * yet and carry secrets that are filled in at hand-over, never stored.
 */
class SystemEventsTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    private const SYSTEM = 'core.notification.system_test';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganisation();
        app(EventTypes::class)->register(new EventType(
            key: self::SYSTEM,
            placeholders: ['secret_code' => 'X', 'sender_name' => 'Amina', 'message' => 'Hi'],
            defaultChannels: [Channels::EMAIL],
            channels: [Channels::EMAIL, Channels::SMS],
            langKey: 'notifications.events.core.notification.test',
            system: true,
            secrets: ['secret_code'],
        ));
    }

    private function sendSystem(array $secrets, string $to = '+254700000999'): NotificationDelivery
    {
        return $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(
            self::SYSTEM, [], ['message' => 'Your link: {secret_code}', 'sender_name' => 'Amina'], null,
            [new NotificationAddress(Channels::SMS, $to, 'Wanjiru', 'en')], $secrets,
        ))->sole());
    }

    public function test_only_system_types_take_addresses_and_secrets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(
            'core.notification.test', [], [], null, [new NotificationAddress(Channels::EMAIL, 'x@example.com', 'X', 'en')],
        )));
    }

    public function test_a_secret_is_filled_in_at_hand_over_and_never_stored(): void
    {
        $delivery = $this->sendSystem(['secret_code' => 'S3CR3T-VALUE']);

        $sent = $this->fakeDriver('sms')->sent;
        $this->assertCount(1, $sent);
        $this->assertSame('+254700000999', $sent[0]->to);
        $this->assertStringContainsString('S3CR3T-VALUE', $sent[0]->body);

        $stored = $this->inTenant(fn () => $delivery->fresh());
        $this->assertNull($stored->user_id);
        $this->assertSame('delivered', $stored->status);
        $this->assertStringContainsString('{secret_code}', $stored->body);
        $this->assertStringNotContainsString('S3CR3T-VALUE', $stored->body);
    }

    public function test_a_retry_carries_the_secret_and_the_job_is_encrypted(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendDelivery('t', 'd', ['secret_code' => 'x']));

        $this->fakeDriver('sms')->failNext(1);
        Queue::fake();
        $delivery = $this->sendSystem(['secret_code' => 'S3CR3T-VALUE']);

        // The first job (dispatched after commit) runs and fails once.
        $first = Queue::pushed(SendDelivery::class)->sole();
        $this->assertSame(['secret_code' => 'S3CR3T-VALUE'], $first->secrets);
        $this->inTenant(fn () => $first->handle(app(ChannelDrivers::class), app(EventTypes::class)));
        $retry = Queue::pushed(SendDelivery::class)->last();
        $this->assertNotSame($first, $retry);
        $this->assertSame(['secret_code' => 'S3CR3T-VALUE'], $retry->secrets);
        $this->assertSame('queued', $this->inTenant(fn () => $delivery->fresh()->status));
    }

    public function test_a_job_without_its_secret_skips_rather_than_sending_a_broken_message(): void
    {
        Queue::fake();
        $delivery = $this->sendSystem(['secret_code' => 'S3CR3T-VALUE']);

        $this->inTenant(fn () => (new SendDelivery($this->owner->tenant_id, $delivery->id))->handle(
            app(ChannelDrivers::class), app(EventTypes::class),
        ));

        $stored = $this->inTenant(fn () => $delivery->fresh());
        $this->assertSame(['skipped', 'secret_missing'], [$stored->status, $stored->reason]);
        $this->assertSame([], $this->fakeDriver('sms')->sent);
    }

    public function test_without_a_notification_sms_driver_a_system_sms_is_still_sent(): void
    {
        config(['notifications.drivers.sms' => 'none']);

        $delivery = $this->sendSystem(['secret_code' => 'S3CR3T-VALUE']);

        // The platform SMS sender (log driver in testing) took it.
        $this->assertSame('sent', $this->inTenant(fn () => $delivery->fresh()->status));
    }
}
