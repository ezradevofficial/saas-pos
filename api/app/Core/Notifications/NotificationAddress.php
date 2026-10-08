<?php

namespace App\Core\Notifications;

use InvalidArgumentException;

/**
 * A contact that is not a user of the tenant yet (an invited email address
 * or phone number), for system event types only (ADR 009). The delivery
 * is written in the current tenant, like any other, under row-level
 * security; it has no user, no preferences, no inbox and no digest.
 */
final class NotificationAddress
{
    public function __construct(
        public readonly string $channel,
        public readonly string $to,
        public readonly string $name,
        public readonly string $locale,
    ) {
        if (! in_array($channel, [Channels::EMAIL, Channels::SMS], true)) {
            throw new InvalidArgumentException("A notification address is an email address or a phone number, not [{$channel}].");
        }
    }
}
