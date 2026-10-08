<?php

namespace App\Core\Currency\Feeds;

use Carbon\CarbonImmutable;

/** A feed whose endpoint the owner has not supplied yet: every fetch throws FeedNotConfigured. */
final class NotConfiguredFeed implements RateFeed
{
    public function __construct(private readonly string $name) {}

    public function fetch(string $base, array $quotes, CarbonImmutable $date): array
    {
        throw FeedNotConfigured::feed($this->name);
    }
}
