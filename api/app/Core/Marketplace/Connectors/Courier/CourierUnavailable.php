<?php

namespace App\Core\Marketplace\Connectors\Courier;

use App\Core\Http\ApiException;

/** MKT-04: no courier is configured (an owner input), or the courier refused the request. */
final class CourierUnavailable extends ApiException
{
    public static function notConfigured(): self
    {
        return new self(503, 'courier_not_configured', __('marketplace.errors.courier_not_configured'));
    }
}
