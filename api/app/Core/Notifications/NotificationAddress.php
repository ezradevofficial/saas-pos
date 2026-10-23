<?php

namespace App\Core\Notifications;

use InvalidArgumentException;

/**
 * A contact that is not a user of the tenant yet (an invited email address
 * or phone number), for system event types only (ADR 009). The delivery
 * is written in the current tenant, like any other, under row-level
 * security; it has no user, no preferences, no inbox and no digest.
 *
 * A marketing event type (NFR-08) reaches customers this way too: the
 * address then names the customer (`customerId`, the party) whose consent
 * decides, and may be a WhatsApp number.
 */
final class NotificationAddress
{
    public function __construct(
        public readonly string $channel,
        public readonly string $to,
        public readonly string $name,
        public readonly string $locale,
        public readonly ?string $customerId = null,
    ) {
        // ECOM-44: a phone number may be reached on WhatsApp (through the channel's driver), also for a
        // contact that is not a customer (a guest's transactional order messages); marketing types
        // still need the customer for their consent check (Notifier).
        if (! in_array($channel, [Channels::EMAIL, Channels::SMS, Channels::WHATSAPP], true)) {
            throw new InvalidArgumentException("A notification address is an email address or a phone number (SMS or WhatsApp), not [{$channel}].");
        }
    }
}
