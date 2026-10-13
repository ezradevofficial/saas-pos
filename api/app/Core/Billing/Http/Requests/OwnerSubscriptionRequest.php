<?php

namespace App\Core\Billing\Http\Requests;

/** SUB-02: the Owner's actions without a body (cancel a scheduled change, subscribe from the trial). */
class OwnerSubscriptionRequest extends SubscriptionRequest
{
    protected string $permission = 'core.subscription.manage';

    protected bool $ownerOnly = true;
}
