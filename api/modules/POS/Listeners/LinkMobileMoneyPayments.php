<?php

namespace Modules\POS\Listeners;

use App\Core\Http\ApiException;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SalePayment;
use Modules\POS\PosServiceProvider;

/**
 * Concept note 7.1: the POS side of mobile money, through the core
 * payment services (the core never reads `pos_*`).
 *
 * - SaleCompleted: a mobile money payment the till recorded with the
 *   provider's code and no payment intent behind it (sold offline, or the
 *   cashier typed the code) becomes a manual intent, verified later by the
 *   provider (C2B confirmation or status check). A payment already paid
 *   through an intent (an STK push, or the online manual call) is left as
 *   it is.
 * - SaleRefunded: a mobile money refund payment the till left `pending`
 *   is paid back through the provider (M-Pesa B2C) to the phone of the
 *   sale's payment; the result shows on the payment intents list.
 */
class LinkMobileMoneyPayments implements ShouldQueue
{
    public function viaQueue(): string
    {
        return (string) config('payments.queue');
    }

    public function handle(SaleCompleted|SaleRefunded $event): void
    {
        app(TenantContext::class)->run($event->tenantId, function () use ($event) {
            if (! app(ModuleRegistry::class)->isActive(PosServiceProvider::MODULE)) {
                return;
            }

            $sale = Sale::query()->find($event->saleId);

            if ($sale === null) {
                return;
            }

            $event instanceof SaleCompleted ? $this->recordSale($sale) : $this->payOut($sale, Refund::query()->findOrFail($event->refundId));
        });
    }

    private function recordSale(Sale $sale): void
    {
        $payments = SalePayment::query()->where('sale_id', $sale->id)->where('method_type', 'mobile_money')->whereNotNull('provider_reference')->get();

        foreach ($payments as $payment) {
            $method = PaymentMethod::query()->find($payment->payment_method_id);
            $code = strtoupper(trim((string) $payment->provider_reference));

            if ($method === null || $this->paidIntent($method, $code) !== null || preg_match('/^[A-Z0-9]{6,20}$/', $code) !== 1) {
                continue;
            }

            try {
                app(PaymentIntents::class)->recordManual($method, [
                    'id' => $payment->id,
                    'company_id' => $sale->company_id,
                    'location_id' => $sale->location_id,
                    'device_id' => $sale->device_id,
                    'payment_method_id' => $method->id,
                    'purpose' => 'sale',
                    'currency' => $payment->currency,
                    'amount_minor' => (int) $payment->amount_minor,
                    'reference_type' => 'pos.sale',
                    'reference' => $sale->id,
                    'created_by' => $sale->cashier_id,
                    'receipt' => $code,
                ]);
            } catch (ApiException $e) {
                // The code already pays another sale: left for the back office (the other intent shows it).
                Log::warning('POS mobile money code not recorded', ['sale' => $sale->id, 'code' => $e->errorCode]);
            }
        }
    }

    private function payOut(Sale $sale, Refund $refund): void
    {
        $payments = RefundPayment::query()->where('refund_id', $refund->id)->where('method_type', 'mobile_money')->where('status', 'pending')->get();

        foreach ($payments as $payment) {
            $method = PaymentMethod::query()->find($payment->payment_method_id);
            $paid = SalePayment::query()->where('sale_id', $sale->id)->where('payment_method_id', $payment->payment_method_id)->whereNotNull('provider_reference')->value('provider_reference');
            $original = $method === null || $paid === null ? null : $this->paidIntent($method, strtoupper((string) $paid));

            if ($original === null) {
                Log::info('POS mobile money refund without a provider payment to pay back to', ['refund' => $refund->id]);

                continue;
            }

            try {
                app(PaymentIntents::class)->payout($method, $original, [
                    'id' => $payment->id,
                    'amount_minor' => (int) $payment->amount_minor,
                    'currency' => $payment->currency,
                    'reference_type' => 'pos.refund',
                    'reference' => $refund->id,
                    'location_id' => $refund->location_id,
                    'device_id' => $refund->device_id,
                    'created_by' => $refund->cashier_id,
                ]);
            } catch (ApiException $e) {
                // Not payable by the provider (another currency, cents): refunded another way.
                Log::warning('POS mobile money refund not paid out', ['refund' => $refund->id, 'code' => $e->errorCode]);
            }
        }
    }

    private function paidIntent(PaymentMethod $method, string $code): ?PaymentIntent
    {
        return PaymentIntent::query()->where('provider', (string) ($method->provider ?? $method->type))
            ->where('provider_receipt', $code)->where('purpose', 'sale')->where('status', 'succeeded')->first();
    }
}
