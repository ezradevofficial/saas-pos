<?php

namespace App\Core\Notifications\Drivers;

/**
 * One message for a ChannelDriver. `to` is an E.164 number for SMS and
 * WhatsApp, the user id for push (the provider resolves their devices).
 */
final class OutgoingMessage
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $channel,
        public readonly string $to,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly ?string $link = null,
    ) {}
}
