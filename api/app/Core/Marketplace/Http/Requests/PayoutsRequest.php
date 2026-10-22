<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** MKT-03: developers' payable balances and ledgers (`platform.marketplace.payouts`). */
class PayoutsRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.marketplace.payouts';
}
