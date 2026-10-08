<?php

namespace App\Core\Currency\Feeds;

use App\Core\Currency\Rate;
use Carbon\CarbonImmutable;

/**
 * CUR-03: a source of official reference rates (Central Bank of Kenya,
 * Banque Centrale du Congo). Returns 1 $base = mid quote for each quote it
 * knows on $date, as `reference` rates; quotes it does not publish are
 * left out.
 *
 * @throws FeedNotConfigured when the feed has no endpoint yet
 */
interface RateFeed
{
    /**
     * @param  list<string>  $quotes
     * @return list<Rate>
     */
    public function fetch(string $base, array $quotes, CarbonImmutable $date): array;
}
