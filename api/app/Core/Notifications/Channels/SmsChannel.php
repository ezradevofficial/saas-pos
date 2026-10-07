<?php

namespace App\Core\Notifications\Channels;

use App\Core\Notifications\Sms\SmsSender;
use Illuminate\Notifications\Notification;

/**
 * Notification channel for text messages. The notification provides
 * toSms($notifiable): string; the notifiable routes `sms` to an E.164 number.
 */
class SmsChannel
{
    public function __construct(private SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('sms', $notification);

        if (! is_string($to) || $to === '') {
            return;
        }

        $this->sender->send($to, $notification->toSms($notifiable));
    }
}
