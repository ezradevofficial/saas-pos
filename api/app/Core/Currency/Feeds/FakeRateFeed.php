<?php

namespace App\Core\Currency\Feeds;

use App\Core\Currency\Rate;
use Carbon\CarbonImmutable;

/**
 * For tests: answers the mids it was given (quote => mid) as reference
 * rates effective at the start of the requested day, and records calls.
 */
final class FakeRateFeed implements RateFeed
{
    /** @var list<array{base: string, quotes: list<string>, date: string}> */
    public array $calls = [];

    /** @param array<string, string> $mids */
    public function __construct(private readonly array $mids, private readonly string $name = 'fake') {}

    public function fetch(string $base, array $quotes, CarbonImmutable $date): array
    {
        $this->calls[] = ['base' => $base, 'quotes' => $quotes, 'date' => $date->toDateString()];

        $rates = [];

        foreach ($quotes as $quote) {
            if (isset($this->mids[$quote])) {
                $rates[] = new Rate($base, $quote, Rate::normalise($this->mids[$quote]), null, null, 'reference', $date->utc()->startOfDay(), $this->name);
            }
        }

        return $rates;
    }
}
