<?php

namespace App\Core\Currency\Feeds;

use App\Core\Currency\Rate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * A feed read from an HTTP endpoint the owner configures. No bank's own
 * page is scraped: the endpoint answers
 * `GET {url}?base=KES&quotes=USD,EUR&date=2026-10-08` with
 *
 *     {"rates": [{"quote": "USD", "mid": "0.00774", "buy": null, "sell": null,
 *                 "effective_at": "2026-10-08T09:00:00Z"}]}
 *
 * meaning 1 base = mid quote. Without a URL every fetch throws
 * FeedNotConfigured (the daily job logs it and moves on).
 */
abstract class EndpointFeed implements RateFeed
{
    public function __construct(protected readonly ?string $url) {}

    abstract public function name(): string;

    public function fetch(string $base, array $quotes, CarbonImmutable $date): array
    {
        if ($this->url === null || $this->url === '') {
            throw FeedNotConfigured::feed($this->name());
        }

        $rows = Http::timeout(15)->acceptJson()->get($this->url, [
            'base' => $base,
            'quotes' => implode(',', $quotes),
            'date' => $date->toDateString(),
        ])->throw()->json('rates');

        if (! is_array($rows)) {
            throw new UnexpectedValueException("The {$this->name()} rate feed answered without a rates list.");
        }

        $rates = [];

        foreach ($rows as $row) {
            $quote = $row['quote'] ?? null;

            if (! in_array($quote, $quotes, true) || ! is_string($row['mid'] ?? null)) {
                continue;
            }

            $rates[] = new Rate(
                base: $base,
                quote: $quote,
                mid: Rate::normalise($row['mid']),
                buy: isset($row['buy']) ? Rate::normalise($row['buy']) : null,
                sell: isset($row['sell']) ? Rate::normalise($row['sell']) : null,
                kind: 'reference',
                effectiveAt: isset($row['effective_at']) ? CarbonImmutable::parse($row['effective_at'])->utc() : $date->startOfDay(),
                source: $this->name(),
            );
        }

        return $rates;
    }
}
