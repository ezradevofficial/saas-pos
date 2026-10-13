<?php

namespace App\Core\Payments\Drivers;

/** SUB-04: Orange Money (DRC). Unavailable until its merchant API and credentials are given (docs/integrations.md). */
class OrangeMoneyProvider extends UnconfiguredProvider
{
    public function name(): string
    {
        return 'orange_money';
    }
}
