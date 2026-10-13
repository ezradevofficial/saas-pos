<?php

namespace App\Core\Billing\Http\Requests;

/** SUB-02: the Owner's actions on the plan without a body (cancel a scheduled change). */
class OwnerSubscriptionRequest extends SubscriptionRequest
{
    protected string $permission = 'core.subscription.manage';

    protected bool $ownerOnly = true;
}
