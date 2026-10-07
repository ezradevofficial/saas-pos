<?php

namespace App\Core\Identity\Notifications;

use App\Core\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A one-time code by email or SMS (AUTH-01, AUTH-03), in the user's language. */
class VerificationCode extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly int $minutes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channel === 'sms' ? [SmsChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.notifications.verification_code.subject', ['app' => config('app.name')]))
            ->greeting(__('auth.notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('auth.notifications.verification_code.line', ['code' => $this->code]))
            ->line(__('auth.notifications.verification_code.expiry', ['minutes' => $this->minutes]))
            ->line(__('auth.notifications.verification_code.ignore'))
            ->salutation(config('app.name'));
    }

    public function toSms(object $notifiable): string
    {
        return __('auth.notifications.verification_code.sms', [
            'app' => config('app.name'),
            'code' => $this->code,
            'minutes' => $this->minutes,
        ]);
    }
}
