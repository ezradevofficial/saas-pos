<?php

namespace App\Core\Billing\Http\Requests;

/** Paying, saving payment methods, subscribing from the trial (`core.subscription.manage`). */
class ManageSubscriptionRequest extends SubscriptionRequest
{
    protected string $permission = 'core.subscription.manage';
}
