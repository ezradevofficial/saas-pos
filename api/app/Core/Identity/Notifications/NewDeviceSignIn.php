<?php

namespace App\Core\Identity\Notifications;

use App\Core\Notifications\Channels\SmsChannel;
use App\Core\Notifications\LocalDate;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AUTH-10: someone signed in to the account from a device not seen before.
 * Queued (a transport failure never fails the sign-in) and sent on demand to
 * the user's address, so the worker needs no tenant context.
 */
class NewDeviceSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $channel,
        public readonly string $ip,
        public readonly ?string $userAgent,
        public readonly CarbonInterface $at,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channel === 'sms' ? [SmsChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.notifications.new_device.subject', ['app' => config('app.name')]))
            ->greeting(__('auth.notifications.greeting', ['name' => $this->name]))
            ->line(__('auth.notifications.new_device.line', ['time' => LocalDate::format($this->at, $this->locale)]))
            ->line(__('auth.notifications.new_device.details', [
                'device' => $this->userAgent ?: '—',
                'ip' => $this->ip,
            ]))
            ->line(__('auth.notifications.new_device.advice'))
            ->salutation(config('app.name'));
    }

    public function toSms(object $notifiable): string
    {
        return __('auth.notifications.new_device.sms', [
            'app' => config('app.name'),
            'time' => LocalDate::format($this->at, $this->locale),
        ]);
    }
}
