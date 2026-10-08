<?php

namespace App\Core\Currency\Feeds;

use InvalidArgumentException;

/**
 * The reference-rate feeds a company can choose (`companies.rate_feed`):
 * none, cbk or bcc. Tests swap a driver for a FakeRateFeed.
 */
class RateFeeds
{
    public const NAMES = ['none', 'cbk', 'bcc'];

    /** @var array<string, RateFeed> */
    private array $swapped = [];

    public function driver(string $name): RateFeed
    {
        if (isset($this->swapped[$name])) {
            return $this->swapped[$name];
        }

        $url = config("services.rate_feeds.{$name}.url");

        return match ($name) {
            'cbk' => new CbkFeed($url),
            'bcc' => new BccFeed($url),
            'none' => new NotConfiguredFeed('none'),
            default => throw new InvalidArgumentException("Unknown rate feed [{$name}]."),
        };
    }

    public function swap(string $name, RateFeed $feed): void
    {
        $this->swapped[$name] = $feed;
    }
}
