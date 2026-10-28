<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\BillingPayment;
use App\Core\Billing\Models\Subscription;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * SUB-04, NFR-06, AUD-01: DRC subscription billing through pawaPay (v2
 * deposits). Amounts are the platform's minor units in pawaPay's decimal
 * strings (CDF whole, USD two places); a callback only names the deposit and
 * pawaPay is always asked again; a repeated callback changes nothing.
 */
class PawapayBillingTest extends TestCase
{
    use BuildsBilling, RefreshTenantDatabase, ResetsPlatformTables;

    private const TOKEN = 'test-only-pawapay-token';

    private const PHONE = '243813456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
        config([
            'billing.drivers.vodacom_mpesa_cd' => 'pawapay',
            'billing.drivers.airtel_money_cd' => 'pawapay',
            'billing.drivers.orange_money_cd' => 'pawapay',
            'payments.pawapay.api_token' => self::TOKEN,
            'payments.pawapay.base_url' => 'https://api.sandbox.pawapay.io',
            'payments.pawapay.verify_signatures' => false,
        ]);
    }

    public function test_a_deposit_is_requested_with_the_exact_cdf_amount_the_chosen_operator_and_a_stable_uuid_v4(): void
    {
        $invoice = $this->congoleseInvoice('CDF', 135000);
        $this->fake($this->accepted('ORANGE_COD'));

        $payment = $this->pay($invoice, 'orange_money_cd')->assertCreated();

        $payment->assertJsonPath('data.status', 'pending');
        $this->assertNull($payment->json('data.redirect_url'));
        $deposit = $this->sent('/v2/deposits');
        $this->assertSame(['135000', 'CDF', 'ORANGE_COD', self::PHONE, 'MMO'], [$deposit['amount'], $deposit['currency'], $deposit['payer']['accountDetails']['provider'], $deposit['payer']['accountDetails']['phoneNumber'], $deposit['payer']['type']]);
        // The text on the customer's phone is a real sentence, in pawaPay's 4 to 22 letters, digits and spaces.
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9 ]{4,22}$/', $deposit['customerMessage']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $deposit['depositId']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/deposits') && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $intent = $this->inOperator(fn () => PaymentIntent::query()->sole());
        $this->assertSame(['pawapay', $deposit['depositId'], 'stk'], [$intent->driver, $intent->provider_request_id, $intent->mode]);
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
    }

    public function test_usd_is_sent_with_two_decimals(): void
    {
        $invoice = $this->congoleseInvoice('USD', 4850);
        $this->fake($this->accepted());

        $this->pay($invoice, 'vodacom_mpesa_cd')->assertCreated();

        $this->assertSame(['48.50', 'USD'], [$this->sent('/v2/deposits')['amount'], $this->sent('/v2/deposits')['currency']]);
    }

    public function test_polling_a_completed_deposit_marks_the_invoice_paid_for_the_exact_amount(): void
    {
        $invoice = $this->congoleseInvoice('CDF', 135000);
        $this->fake($this->accepted());
        $paymentId = $this->pay($invoice, 'vodacom_mpesa_cd')->json('data.id');
        $this->fake($this->statusStub('COMPLETED', '135000.00'));

        $this->getJson("/api/v1/subscription/payments/{$paymentId}", $this->headersFor())->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertSame('paid', $this->operatorInvoice($invoice->operator_invoice_id)->status);
        $this->assertSame('paid', $this->inTenant(fn () => $invoice->fresh()->status));
    }

    public function test_a_declined_deposit_is_cancelled_and_a_rejected_one_fails_and_neither_pays(): void
    {
        $invoice = $this->congoleseInvoice('CDF', 135000);
        $this->fake($this->accepted('AIRTEL_COD'));
        $paymentId = $this->pay($invoice, 'airtel_money_cd')->json('data.id');
        $this->fake($this->statusStub('FAILED', '135000', ['failureCode' => 'PAYMENT_NOT_APPROVED', 'failureMessage' => 'x']));

        $this->getJson("/api/v1/subscription/payments/{$paymentId}", $this->headersFor())->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);

        $this->fake(['api.sandbox.pawapay.io/v2/predict-provider' => $this->predict('AIRTEL_COD'), 'api.sandbox.pawapay.io/v2/deposits' => Http::response(['status' => 'REJECTED', 'failureReason' => ['failureCode' => 'INVALID_PHONE_NUMBER', 'failureMessage' => 'x']])]);
        $this->pay($invoice, 'airtel_money_cd')->assertCreated()->assertJsonPath('data.status', 'failed');
    }

    public function test_a_deposit_pawapay_does_not_know_after_a_lost_answer_fails_and_a_server_error_keeps_checking(): void
    {
        $invoice = $this->congoleseInvoice('CDF', 135000);
        $this->fake(['api.sandbox.pawapay.io/v2/predict-provider' => $this->predict('VODACOM_MPESA_COD'), 'api.sandbox.pawapay.io/v2/deposits' => Http::response('', 500)]);

        $paymentId = $this->pay($invoice, 'vodacom_mpesa_cd')->assertCreated()->json('data.id');
        $this->assertContains($this->inTenant(fn () => BillingPayment::query()->findOrFail($paymentId)->status), ['pending', 'unknown']);

        $this->fake(['api.sandbox.pawapay.io/v2/deposits/*' => Http::response(['status' => 'NOT_FOUND'])]);
        $this->getJson("/api/v1/subscription/payments/{$paymentId}", $this->headersFor())->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
    }

    public function test_a_completed_callback_rechecks_with_pawapay_and_a_duplicate_callback_changes_nothing(): void
    {
        $invoice = $this->congoleseInvoice('CDF', 135000);
        $this->fake($this->accepted());
        $this->pay($invoice, 'vodacom_mpesa_cd')->assertCreated();
        $depositId = $this->sent('/v2/deposits')['depositId'];
        $this->fake($this->statusStub('COMPLETED', '135000'));

        $this->webhookCall('deposits', ['depositId' => $depositId, 'status' => 'COMPLETED'])->assertOk();
        $this->webhookCall('deposits', ['depositId' => $depositId, 'status' => 'COMPLETED'])->assertOk();

        $this->assertSame('paid', $this->operatorInvoice($invoice->operator_invoice_id)->status);
        $this->assertSame(1, count(array_filter($this->ownerNotices(), fn ($t) => $t === 'core.subscription.payment_received')));
        $this->assertSame(1, $this->inOperator(fn () => PaymentIntent::query()->count()));
    }

    public function test_a_callback_the_status_check_does_not_confirm_pays_nothing(): void
    {
        $invoice = $this->congoleseInvoice('CDF', 135000);
        $this->fake($this->accepted());
        $this->pay($invoice, 'vodacom_mpesa_cd')->assertCreated();
        $depositId = $this->sent('/v2/deposits')['depositId'];

        // The callback claims COMPLETED; pawaPay says it is still processing, then that it failed.
        foreach (['PROCESSING', 'FAILED'] as $status) {
            $this->fake($this->statusStub($status, '135000', $status === 'FAILED' ? ['failureCode' => 'OTHER_ERROR', 'failureMessage' => 'x'] : null));
            $this->webhookCall('deposits', ['depositId' => $depositId, 'status' => 'COMPLETED', 'amount' => '135000', 'currency' => 'CDF'])->assertOk();
            $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
        }
        $this->assertSame('failed', $this->inOperator(fn () => PaymentIntent::query()->sole()->status));
    }

    public function test_an_amount_or_currency_that_is_not_the_invoices_is_never_a_success(): void
    {
        $invoice = $this->congoleseInvoice('CDF', 135000);

        foreach ([['134999', 'CDF'], ['135000', 'USD'], ['135000.50', 'CDF'], ['abc', 'CDF']] as [$amount, $currency]) {
            $this->fake($this->accepted());
            $this->pay($invoice, 'vodacom_mpesa_cd')->assertCreated();
            $depositId = $this->sent('/v2/deposits')['depositId'];
            $this->fake($this->statusStub('COMPLETED', $amount, null, $currency));

            $this->webhookCall('deposits', ['depositId' => $depositId])->assertOk();

            $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status, "{$amount} {$currency}");
            $this->assertSame('failed', $this->inOperator(fn () => PaymentIntent::query()->where('provider_request_id', $depositId)->sole()->status));
        }
    }

    public function test_callbacks_for_unknown_deposits_and_the_other_kinds_are_acknowledged_and_ask_nothing(): void
    {
        $this->fake([]);

        $this->webhookCall('deposits', ['depositId' => (string) Str::uuid()])->assertOk();
        $this->webhookCall('deposits', ['depositId' => 'nope'])->assertOk();
        $this->webhookCall('deposits', [])->assertOk();
        $this->webhookCall('refunds', ['refundId' => (string) Str::uuid()])->assertOk();
        $this->webhookCall('payouts', ['payoutId' => (string) Str::uuid()])->assertOk();
        $this->webhookCall('checkouts', ['checkoutId' => (string) Str::uuid()])->assertOk();

        Http::assertNothingSent();
    }

    public function test_callbacks_are_refused_without_a_valid_signature_when_signatures_are_on(): void
    {
        config(['payments.pawapay.verify_signatures' => true]);
        $this->fake([]);

        $this->webhookCall('deposits', ['depositId' => (string) Str::uuid(), 'status' => 'COMPLETED'])->assertUnauthorized();
        $this->webhookCall('refunds', [])->assertUnauthorized();
        $this->webhookCall('checkouts', [])->assertUnauthorized();
    }

    public function test_a_phone_that_is_not_the_chosen_operators_or_not_a_congolese_number_is_refused_before_any_deposit(): void
    {
        $invoice = $this->congoleseInvoice('CDF', 135000);

        $this->fake(['api.sandbox.pawapay.io/v2/predict-provider' => $this->predict('AIRTEL_COD')]);
        $this->pay($invoice, 'vodacom_mpesa_cd')->assertCreated()->assertJsonPath('data.status', 'failed');
        $this->assertNull($this->sentOrNull('/v2/deposits'));

        $this->postJson("/api/v1/subscription/invoices/{$invoice->id}/pay", ['provider' => 'vodacom_mpesa_cd', 'phone' => '12345'], $this->headersFor())
            ->assertStatus(422)->assertJsonPath('code', 'phone_invalid');
    }

    private function congoleseInvoice(string $currency, int $total): BillingInvoice
    {
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');
        $invoice = $this->inTenant(fn () => BillingInvoice::query()->findOrFail($invoice));
        $cd = $this->inOperator(fn () => Company::query()->where('country', 'CD')->value('id'));

        // A DRC customer's invoice, in its currency (the catalogue's CD prices are not what is tested here).
        $this->inOperator(fn () => DB::connection(TenantContext::CONNECTION)->table('operator_invoices')->where('id', $invoice->operator_invoice_id)
            ->update(['company_id' => $cd, 'currency' => $currency, 'total_minor' => $total]));
        $this->inTenant(function () use ($invoice, $currency, $total) {
            DB::connection(TenantContext::CONNECTION)->table('billing_invoices')->where('id', $invoice->id)->update(['currency' => $currency, 'total_minor' => $total]);
            DB::connection(TenantContext::CONNECTION)->table('subscriptions')->update(['country' => 'CD', 'currency' => $currency]);
        });

        return $this->inTenant(fn () => BillingInvoice::query()->findOrFail($invoice->id));
    }

    private function pay(BillingInvoice $invoice, string $provider, string $phone = '0813456789'): TestResponse
    {
        return $this->postJson("/api/v1/subscription/invoices/{$invoice->id}/pay", ['provider' => $provider, 'phone' => $phone], $this->headersFor());
    }

    /** Http::fake adds stubs after the earlier ones, where the first match wins: start from a clean factory. */
    private function fake(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    private function accepted(string $code = 'VODACOM_MPESA_COD'): array
    {
        return [
            'api.sandbox.pawapay.io/v2/predict-provider' => $this->predict($code),
            'api.sandbox.pawapay.io/v2/deposits' => fn (Request $request) => Http::response(['depositId' => $request['depositId'], 'status' => 'ACCEPTED', 'nextStep' => 'FINAL_STATUS', 'created' => '2026-11-01T08:00:00Z']),
        ];
    }

    private function predict(string $provider): mixed
    {
        return Http::response(['country' => 'COD', 'provider' => $provider, 'phoneNumber' => self::PHONE]);
    }

    private function statusStub(string $status, string $amount, ?array $failure = null, string $currency = 'CDF'): array
    {
        return ['api.sandbox.pawapay.io/v2/deposits/*' => fn (Request $request) => Http::response(['status' => 'FOUND', 'data' => array_filter([
            'depositId' => basename($request->url()), 'status' => $status, 'amount' => $amount, 'currency' => $currency, 'country' => 'COD',
            'payer' => ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => self::PHONE, 'provider' => 'VODACOM_MPESA_COD']],
            'providerTransactionId' => 'PP-TXN-'.substr(basename($request->url()), 0, 8), 'failureReason' => $failure,
        ])])];
    }

    private function webhookCall(string $kind, array $body): TestResponse
    {
        return $this->postJson('/api/v1/billing/pawapay/'.$kind, $body);
    }

    private function sentOrNull(string $path): ?array
    {
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), $path)) {
                return $request->data();
            }
        }

        return null;
    }

    private function sent(string $path): array
    {
        return $this->sentOrNull($path) ?? $this->fail("No request to {$path}.");
    }
}
