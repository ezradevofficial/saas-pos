<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** Platform console: tenants, their subscriptions and invoices (every staff role, `billing.view`). */
class PlatformBillingRequest extends PlatformRequest
{
    protected ?string $ability = 'billing.view';
}
