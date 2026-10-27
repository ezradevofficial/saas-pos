<?php

namespace Modules\Salon\Payments;

use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Salon\Models\Payment;
use Modules\Salon\SalonServiceProvider;

/**
 * SAL-11: what the payment provider settled (core PaymentIntentSettled) for a deposit (reference type `salon.payment`),
 * brought back to the appointment: an M-Pesa push that succeeded holds the deposit, one that failed or timed out marks
 * it failed. Deposits::settle applies each once.
 */
class SettleDeposit implements ShouldQueue
{
    public function viaQueue(): string
    {
        return (string) config('payments.queue');
    }

    public function handle(PaymentIntentSettled $event): void
    {
        if ($event->referenceType !== Payment::REFERENCE) {
            return;
        }

        app(TenantContext::class)->run($event->tenantId, function () use ($event) {
            $intent = PaymentIntent::query()->find($event->intentId);

            if ($intent !== null && app(ModuleRegistry::class)->isActive(SalonServiceProvider::MODULE)) {
                app(Deposits::class)->settle($intent);
            }
        });
    }
}
