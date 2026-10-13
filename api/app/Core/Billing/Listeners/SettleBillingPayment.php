<?php

namespace App\Core\Billing\Listeners;

use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Operator\Operator;
use App\Core\Billing\Payments\BillingPayments;
use App\Core\Payments\Events\PaymentIntentSettled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * SUB-04, SUB-06: an operator payment intent for a platform invoice
 * reached its final status. Only intents of the operator tenant with
 * reference `billing.invoice` are taken; the settlement itself enters the
 * operator's then the customer's context (BillingPayments::settle).
 * Queued with the payment work; it enters each tenant itself.
 */
class SettleBillingPayment implements ShouldQueue
{
    public function viaQueue(): string
    {
        return (string) config('payments.queue', 'payments');
    }

    public function __construct(
        private readonly Operator $operator,
        private readonly BillingPayments $payments,
    ) {}

    public function handle(PaymentIntentSettled $event): void
    {
        if ($event->referenceType !== Invoicing::REFERENCE_TYPE || ! $this->operator->is($event->tenantId)) {
            return;
        }

        $this->payments->settle($event->intentId, $event->reference);
    }
}
