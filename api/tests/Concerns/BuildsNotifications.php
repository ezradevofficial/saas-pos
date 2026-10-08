<?php

namespace Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\Drivers\FakeChannelDriver;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use Illuminate\Support\Collection;

/**
 * NOT-01..NOT-06 tests: event types registered by a test module, sending
 * as a module would, and the fake channel drivers.
 */
trait BuildsNotifications
{
    use BuildsOrganisation;

    /** An approval-like event (mandatory allowed) and an informational one (not). */
    protected function registerTestEventTypes(): void
    {
        $types = app(EventTypes::class);
        $types->register(new EventType(
            key: 'core.approval.requested',
            placeholders: ['document_number' => 'PO-0042', 'amount' => 'KES 12,450.00', 'requester_name' => 'Juma Mwangi'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
            langKey: 'notifications.events.core.notification.test',
        ));
        $types->register(new EventType(
            key: 'core.report.ready',
            placeholders: ['report_name' => 'Sales by day'],
            defaultChannels: [Channels::IN_APP],
            channels: [Channels::IN_APP, Channels::EMAIL],
            langKey: 'notifications.events.core.notification.test',
        ));
    }

    /**
     * Send the core's test event to $recipients in the owner's tenant.
     *
     * @param  iterable<User|string>  $recipients
     */
    protected function sendTest(iterable $recipients, array $data = [], ?string $type = null, ?string $link = '/notifications'): Collection
    {
        return $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(
            $type ?? 'core.notification.test',
            $recipients,
            $data + ['sender_name' => 'Amina', 'message' => 'Stock count at 5 pm.'],
            $link,
        )));
    }

    protected function fakeDriver(string $channel): FakeChannelDriver
    {
        $driver = app(ChannelDrivers::class)->for($channel);
        $this->assertInstanceOf(FakeChannelDriver::class, $driver);

        return $driver;
    }

    /** A colleague with a verified phone (SMS, WhatsApp) and email. */
    protected function reachableColleague(array $attributes = []): User
    {
        return $this->inTenant(fn () => $this->colleague($this->owner, $attributes + [
            'name' => 'Wanjiku',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'phone_verified_at' => now(),
        ]));
    }
}
