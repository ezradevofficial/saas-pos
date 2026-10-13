<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Dunning\BillingCycle;
use App\Core\Billing\Http\Requests\ChangeSubscriptionRequest;
use App\Core\Billing\Http\Requests\OwnerSubscriptionRequest;
use App\Core\Billing\Http\Requests\QuoteSubscriptionRequest;
use App\Core\Billing\Http\Requests\SubscriptionRequest;
use App\Core\Billing\Http\Requests\SubscriptionStatusRequest;
use App\Core\Billing\Http\Resources\BillingInvoiceResource;
use App\Core\Billing\Http\SubscriptionPresenter;
use App\Core\Billing\Subscriptions\Subscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * SUB-02..SUB-06, the Owner's Settings → Subscription: the plan, its
 * status, quotes and confirmed changes, and subscribing from the trial.
 */
class SubscriptionController
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly BillingCycle $cycle,
    ) {}

    /** GET subscription/status: the banner (every signed-in user). */
    public function status(SubscriptionStatusRequest $request): JsonResponse
    {
        return response()->json(['data' => SubscriptionPresenter::status($this->subscriptions->current())]);
    }

    /** GET subscription */
    public function show(SubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->require();

        return response()->json(['data' => SubscriptionPresenter::subscription($subscription, $this->cycle->nextInvoice($subscription))]);
    }

    /** POST subscription/quote: what a change would cost now and when it takes effect; changes nothing. */
    public function quote(QuoteSubscriptionRequest $request): JsonResponse
    {
        $quote = $this->subscriptions->quote($this->subscriptions->require(), $request->plan(), $request->coupon());

        return response()->json(['data' => Subscriptions::withoutInternals($quote)]);
    }

    /** POST subscription/change: confirm a quoted change. */
    public function change(ChangeSubscriptionRequest $request): JsonResponse
    {
        $result = $this->subscriptions->change($this->subscriptions->require(), $request->plan(), $request->coupon(), (int) $request->validated('expected_charge'), $request->actor());
        $subscription = $result['subscription'];

        return response()->json(['data' => [
            'quote' => $result['quote'],
            'subscription' => SubscriptionPresenter::subscription($subscription, $this->cycle->nextInvoice($subscription)),
            'invoice' => $result['invoice'] === null ? null : BillingInvoiceResource::make($result['invoice'])->resolve($request),
        ]]);
    }

    /** POST subscription/pending/cancel: drop a change waiting for the period end. */
    public function cancelPending(OwnerSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->cancelPending($this->subscriptions->require());

        return response()->json(['data' => SubscriptionPresenter::subscription($subscription, $this->cycle->nextInvoice($subscription))]);
    }

    /** POST subscription/subscribe (the Owner's): issue the first invoice now, due at the trial end. */
    public function subscribe(OwnerSubscriptionRequest $request): JsonResponse
    {
        $invoice = $this->cycle->subscribeNow($this->subscriptions->require(), CarbonImmutable::now());

        return response()->json(['data' => BillingInvoiceResource::make($invoice)->resolve($request)], 201);
    }
}
