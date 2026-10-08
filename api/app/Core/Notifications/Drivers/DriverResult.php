<?php

namespace App\Core\Notifications\Drivers;

/** What a provider answered: its message id, and whether it already reports the message delivered (NOT-06). */
final class DriverResult
{
    public function __construct(
        public readonly ?string $providerMessageId = null,
        public readonly bool $delivered = false,
    ) {}
}
