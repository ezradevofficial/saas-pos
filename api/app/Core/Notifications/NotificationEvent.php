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
 */
final class NotificationEvent
{
    /** @var list<string> */
    public readonly array $recipientIds;

    /**
     * @param  iterable<User|string>  $recipients  users or user ids of the current tenant
     * @param  array<string, string|int|float|null>  $data
     */
    public function __construct(
        public readonly string $type,
        iterable $recipients,
        public readonly array $data = [],
        public readonly ?string $link = null,
    ) {
        $ids = [];

        foreach ($recipients as $recipient) {
            $ids[] = $recipient instanceof User ? (string) $recipient->getKey() : (string) $recipient;
        }

        $this->recipientIds = array_values(array_unique($ids));
    }
}
