<?php

namespace App\Core\Notifications;

use App\Core\Notifications\Console\SendNotificationDigests;
use App\Core\Notifications\Digest\Digests;
use App\Core\Notifications\Digest\RecipientTimezone;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\Templates\Templates;
use Illuminate\Support\ServiceProvider;

/**
 * NOT-01..NOT-06: the core notification service. Modules register their
 * event types on EventTypes in their own provider's boot() and send
 * through Notifier.
 */
class NotificationsServiceProvider extends ServiceProvider
{
    /** The core's own sample event: an admin's test message (exercises every channel end to end). */
    public const TEST_EVENT = 'core.notification.test';

    public function register(): void
    {
        $this->app->singleton(EventTypes::class);
        $this->app->singleton(ChannelDrivers::class);
        $this->app->singleton(Preferences::class);
        $this->app->singleton(Templates::class);
        $this->app->singleton(Notifier::class);
        $this->app->singleton(Digests::class);
        // Per job and request: a cached zone must not outlive a change of assignment.
        $this->app->scoped(RecipientTimezone::class);
    }

    public function boot(): void
    {
        $this->app->make(EventTypes::class)->register(new EventType(
            key: self::TEST_EVENT,
            placeholders: ['sender_name' => 'Amina Otieno', 'message' => 'The shop opens at 08:00 tomorrow.'],
            defaultChannels: [Channels::IN_APP, Channels::EMAIL],
            mandatoryAllowed: true,
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([SendNotificationDigests::class]);
        }
    }
}
