<?php

namespace Modules\POS\Listeners;

use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SalePayment;
use Modules\POS\Payments\RecordFlags;
use Modules\POS\PosServiceProvider;

/**
 * Concept note 7.1: what the provider settled, brought back to the POS
 * records (core's PaymentIntentSettled; the core never writes `pos_*`).
 *
 * - `pos.sale`: the sale's mobile money payment (the intent's own id, or
 *   the payment carrying its receipt) becomes `confirmed` once the push
 *   was paid or the typed code verified; a mismatch (another amount, a
 *   code M-Pesa does not know) flags the sale `mpesa_mismatch`.
 * - `pos.refund`: the refund payment paid back by B2C becomes `confirmed`;
 *   a payout that failed or timed out flags the refund `payout_failed`.
 *
 * A sale not uploaded yet is updated when it is (LinkMobileMoneyPayments).
 */
class ApplyPaymentSettlement implements ShouldQueue
{
    public function viaQueue(): string
    {
        return (string) config('payments.queue');
    }

    public function handle(PaymentIntentSettled $event): void
    {
        if (! in_array($event->referenceType, ['pos.sale', 'pos.refund'], true)) {
            return;
        }

        app(TenantContext::class)->run($event->tenantId, function () use ($event) {
            $intent = PaymentIntent::query()->find($event->intentId);

            if ($intent === null || ! app(ModuleRegistry::class)->isActive(PosServiceProvider::MODULE)) {
                return;
            }

            DB::transaction(fn () => $event->referenceType === 'pos.sale' ? $this->sale($intent) : $this->refund($intent));
        });
    }

    private function sale(PaymentIntent $intent): void
    {
        $sale = Sale::query()->find($intent->reference);

        if ($sale === null) {
            return;
        }

        $payment = SalePayment::query()->where('sale_id', $sale->id)
            ->where(fn ($q) => $q->whereKey($intent->id)->when($intent->provider_receipt !== null, fn ($q) => $q->orWhere('provider_reference', $intent->provider_receipt)))
            ->first();

        if ($payment === null) {
            return;
        }

        $paid = $intent->verification === 'verified' || ($intent->mode === 'stk' && $intent->status === 'succeeded');
        $mismatch = $intent->verification === 'mismatch' || ($intent->mode === 'stk' && in_array($intent->status, ['failed', 'cancelled', 'timeout'], true));

        if ($paid && $payment->status !== SalePayment::CONFIRMED) {
            $payment->forceFill(['status' => SalePayment::CONFIRMED])->save();
        }

        if ($mismatch) {
            RecordFlags::add($sale, 'mpesa_mismatch', ['payment_id' => $payment->id, 'result_code' => $intent->result_code]);
        }
    }

    private function refund(PaymentIntent $intent): void
    {
        $payment = RefundPayment::query()->whereKey($intent->id)->first();

        if ($payment === null) {
            return;
        }

        if ($intent->status === 'succeeded') {
            $payment->forceFill(['status' => SalePayment::CONFIRMED, 'provider_reference' => $intent->provider_receipt ?? $payment->provider_reference])->save();

            return;
        }

        if (in_array($intent->status, ['failed', 'cancelled', 'timeout'], true)) {
            RecordFlags::add(Refund::query()->findOrFail($payment->refund_id), 'payout_failed', ['payment_id' => $payment->id, 'result_code' => $intent->result_code]);
        }
    }
}
