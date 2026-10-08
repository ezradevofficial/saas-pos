<?php

namespace App\Core\Currency\Feeds;

use App\Core\Currency\Rate;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * A feed read from an HTTP endpoint the owner configures. No bank's own
 * page is scraped: the endpoint answers
 * `GET {url}?base=KES&quotes=USD,EUR&date=2026-10-08` with
 *
 *     {"rates": [{"quote": "USD", "mid": "0.00774", "buy": null, "sell": null,
 *                 "effective_at": "2026-10-08T09:00:00Z"}]}
 *
 * meaning 1 base = mid quote. Rows for other quotes are ignored; a
 * malformed row (not an object, mid/buy/sell not a decimal string that is
 * still positive at 8 decimals, bad time) is logged and skipped (CUR-03). Without a URL every fetch throws
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

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                Log::warning("Reference rate row skipped ({$this->name()} feed): the row is not an object", ['row' => $index]);

                continue;
            }

            if (! in_array($row['quote'] ?? null, $quotes, true)) {
                continue;
            }

            // One bad row is logged and skipped; the rest of the day still loads.
            try {
                $rates[] = $this->rate($base, $row, $date);
            } catch (Throwable $e) {
                Log::warning("Reference rate row skipped ({$this->name()} feed): {$e->getMessage()}", ['row' => $index, 'quote' => $row['quote']]);
            }
        }

        return $rates;
    }

    private function rate(string $base, array $row, CarbonImmutable $date): Rate
    {
        $value = function (string $field, bool $required) use ($row): ?string {
            $raw = $row[$field] ?? null;

            if ($raw === null && ! $required) {
                return null;
            }

            if (! is_string($raw) || preg_match('/^\d{1,10}(\.\d+)?\z/', $raw) !== 1) {
                throw new UnexpectedValueException("{$field} is not a decimal string");
            }

            // Positive after rounding to the stored 8 decimals: 0.000000001 would be stored as 0.
            $normalised = Rate::normalise($raw);

            if (BigDecimal::of($normalised)->isZero()) {
                throw new UnexpectedValueException("{$field} is not above zero at 8 decimals");
            }

            return $normalised;
        };

        return new Rate(
            base: $base,
            quote: $row['quote'],
            mid: $value('mid', true),
            buy: $value('buy', false),
            sell: $value('sell', false),
            kind: 'reference',
            effectiveAt: isset($row['effective_at']) ? CarbonImmutable::parse($row['effective_at'])->utc() : $date->utc()->startOfDay(),
            source: $this->name(),
        );
    }
}
