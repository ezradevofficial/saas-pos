<?php

namespace App\Core\Currency\Feeds;

use RuntimeException;

/** A reference-rate feed without an endpoint (`services.rate_feeds.{feed}.url`). */
class FeedNotConfigured extends RuntimeException
{
    public static function feed(string $name): self
    {
        return new self("The {$name} rate feed has no endpoint configured (services.rate_feeds.{$name}.url).");
    }
}
