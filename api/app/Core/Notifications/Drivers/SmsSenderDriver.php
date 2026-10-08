<?php

namespace App\Core\Notifications\Drivers;

use App\Core\Notifications\Sms\SmsSender;

/**
 * The platform's SMS sender (the one that sends one-time codes) as a
 * notification driver, for system event types when no notification SMS
 * driver is configured (ADR 009): a phone-only user's sign-in alert or an
 * invitation by SMS must go out wherever codes do.
 */
final class SmsSenderDriver implements ChannelDriver
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(OutgoingMessage $message): DriverResult
    {
        $this->sender->send($message->to, $message->body);

        return new DriverResult;
    }
}
