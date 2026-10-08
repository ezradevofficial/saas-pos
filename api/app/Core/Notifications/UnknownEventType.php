<?php

namespace App\Core\Notifications;

use InvalidArgumentException;

/** A notification was sent, or configured, for an event type no module registered (NOT-02). */
class UnknownEventType extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct("Unknown notification event type [{$key}]. Register it with EventTypes::register().");
    }
}
