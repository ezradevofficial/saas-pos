<?php

namespace App\Core\Identity\Notifications;

use App\Core\Notifications\Channels\SmsChannel;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** AUTH-10: someone signed in to the account from a device not seen before. */
class NewDeviceSignIn extends Notification
{
    public function __construct(
        public readonly string $ip,
        public readonly ?string $userAgent,
        public readonly CarbonInterface $at,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->email !== null ? ['mail'] : [SmsChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.notifications.new_device.subject', ['app' => config('app.name')]))
            ->greeting(__('auth.notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('auth.notifications.new_device.line', ['time' => $this->at->format('Y-m-d H:i').' UTC']))
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
            'time' => $this->at->format('Y-m-d H:i').' UTC',
        ]);
    }
}
