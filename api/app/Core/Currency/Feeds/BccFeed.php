<?php

namespace App\Core\Currency\Feeds;

/** CUR-03: Banque Centrale du Congo reference rates (CDF), endpoint `services.rate_feeds.bcc.url`. */
final class BccFeed extends EndpointFeed
{
    public function name(): string
    {
        return 'bcc';
    }
}
