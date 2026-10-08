<?php

namespace App\Core\Notifications;

use App\Core\Identity\Models\User;

/**
 * One thing that happened, to tell some users about (NOT-02): passed to
 * Notifier::send().
 *
 * `data` fills the event type's placeholders; values are already
 * formatted for display by the sender (money with its currency code first,
 * dates in the user's style). `link` is a path in the web app (or a full
 * URL) the in-app notification and the email point to.
 *
 * System event types only (ADR 009): `addresses` reach contacts that are
 * not users yet, and `secrets` fill the type's secret placeholders (in the
 * text or the link) at hand-over, never stored.
 */
final class NotificationEvent
{
    /** @var list<string> */
    public readonly array $recipientIds;

    /**
     * @param  iterable<User|string>  $recipients  users or user ids of the current tenant
     * @param  array<string, string|int|float|null>  $data
     * @param  list<NotificationAddress>  $addresses
     * @param  array<string, string>  $secrets
     */
    public function __construct(
        public readonly string $type,
        iterable $recipients,
        public readonly array $data = [],
        public readonly ?string $link = null,
        public readonly array $addresses = [],
        public readonly array $secrets = [],
    ) {
        $ids = [];

        foreach ($recipients as $recipient) {
            $ids[] = $recipient instanceof User ? (string) $recipient->getKey() : (string) $recipient;
        }

        $this->recipientIds = array_values(array_unique($ids));
    }
}
