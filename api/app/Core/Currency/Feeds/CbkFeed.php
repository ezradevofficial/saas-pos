<?php

namespace App\Core\Currency\Feeds;

/** CUR-03: Central Bank of Kenya reference rates (KES), endpoint `services.rate_feeds.cbk.url`. */
final class CbkFeed extends EndpointFeed
{
    public function name(): string
    {
        return 'cbk';
    }
}
