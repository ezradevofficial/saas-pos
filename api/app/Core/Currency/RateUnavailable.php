<?php

namespace App\Core\Currency;

use App\Core\Http\ApiException;

/** No rate, direct or inverse, for a pair at a time (CUR-03): 422 `rate_unavailable`. */
class RateUnavailable extends ApiException
{
    public static function for(string $from, string $to): self
    {
        return new self(422, 'rate_unavailable', __('core.exchange_rate.unavailable', ['from' => $from, 'to' => $to]));
    }
}
