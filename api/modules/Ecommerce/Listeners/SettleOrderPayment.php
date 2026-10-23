<?php

namespace Modules\Ecommerce\Listeners;

use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Orders\OrderPayments;
use Modules\Ecommerce\Orders\ReturnRefunds;

/**
 * ECOM-15: what the provider settled (core PaymentIntentSettled), brought
 * back to the order (the core never writes `ecom_*`): payments of
 * reference type `ecommerce.order` and refund payouts of
 * `ecommerce.refund`, and a return's refund payouts of
 * `ecommerce.return_refund` (ECOM-42, ReturnRefunds::settle). OrderPayments::settle applies each once.
 */
class SettleOrderPayment implements ShouldQueue
{
    public function viaQueue(): string
    {
        return (string) config('payments.queue');
    }

    public function handle(PaymentIntentSettled $event): void
    {
        if (! in_array($event->referenceType, [OrderPayments::REFERENCE, OrderPayments::REFUND_REFERENCE, ReturnRefunds::REFERENCE], true)) {
            return;
        }

        app(TenantContext::class)->run($event->tenantId, function () use ($event) {
            $intent = PaymentIntent::query()->find($event->intentId);

            if ($intent !== null && app(ModuleRegistry::class)->isActive(EcommerceServiceProvider::MODULE)) {
                $event->referenceType === ReturnRefunds::REFERENCE ? app(ReturnRefunds::class)->settle($intent) : app(OrderPayments::class)->settle($intent);
            }
        });
    }
}
