<?php

namespace App\Core\Identity\Notifications;

use App\Core\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A one-time code by email or SMS (AUTH-01, AUTH-03, AUTH-04), in the user's
 * language, worded for its purpose (verify_contact, two_factor, password_reset).
 *
 * The recorded exception to NOT-02 (ADR 009): codes never go through the
 * Notifier. They must leave at once (no queue, no digest), can never be
 * switched off, and their text must never be stored: the Notifier keeps
 * every message body in `notification_deliveries`, while the code itself
 * is kept only as an HMAC (VerificationChallenge).
 */
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
            ->subject(__($this->key('subject'), ['app' => config('app.name')]))
            ->greeting(__('auth.notifications.greeting', ['name' => $notifiable->name]))
            ->line(__($this->key('line'), ['code' => $this->code]))
            ->line(trans_choice('auth.notifications.verification_code.expiry', $this->minutes, ['minutes' => $this->minutes]))
            ->line(__('auth.notifications.verification_code.ignore'))
            ->salutation(config('app.name'));
    }

    public function toSms(object $notifiable): string
    {
        return trans_choice($this->key('sms'), $this->minutes, [
            'app' => config('app.name'),
            'code' => $this->code,
            'minutes' => $this->minutes,
        ]);
    }

    private function key(string $part): string
    {
        return 'auth.notifications.verification_code.'.$this->purpose.'.'.$part;
    }
}
