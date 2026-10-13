<?php

namespace App\Core\Payments\Drivers;

/** SUB-04: Airtel Money (Kenya and the DRC). Unavailable until its merchant API and credentials are given (docs/integrations.md). */
class AirtelMoneyProvider extends UnconfiguredProvider
{
    public function name(): string
    {
        return 'airtel_money';
    }
}
