<?php

namespace App\Core\Notifications\Sms;

/**
 * Bound outside local and testing when no SMS provider is configured: a
 * text message is never silently written to a log in a real environment.
 */
class NullSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        throw new SmsNotConfigured;
    }
}
