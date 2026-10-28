<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Audit\AuditEntry;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\BillingPayment;
use App\Core\Billing\Models\BillingPaymentMethod;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\Paystack\PaystackProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * SUB-04, MD-04, NFR-06, AUD-01: Kenya subscription billing through Paystack.
 * The checkout carries the exact amount and our reference; the webhook is
 * signed (401 otherwise), idempotent, and only a Paystack verify with the
 * invoice's exact amount and currency marks an invoice paid; a reusable card
 * is saved without card numbers and charged for renewals.
 */
class PaystackBillingTest extends TestCase
{
    use BuildsBilling, RefreshTenantDatabase, ResetsPlatformTables;

    private const KEY = 'test-only-paystack-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
        config([
            'billing.drivers.card_aggregator' => 'paystack',
            'payments.paystack.secret_key' => self::KEY,
            'payments.paystack.base_url' => 'https://api.paystack.co',
            'payments.paystack.callback_url' => 'https://app.example.com/settings/subscription/paystack-return',
        ]);
    }

    public function test_pay_initializes_a_transaction_with_the_exact_amount_reference_and_callback_and_sends_the_payer_to_paystack(): void
    {
        $this->fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'ac1', 'reference' => 'x']])]);
        $invoice = $this->subscribed();

        $payment = $this->postJson("/api/v1/subscription/invoices/{$invoice->id}/pay", ['provider' => 'card_aggregator'], $this->headersFor())->assertCreated();

        $payment->assertJsonPath('data.status', 'pending')->assertJsonPath('data.redirect_url', 'https://checkout.paystack.com/abc123');
        Http::assertSent(function (Request $request) use ($invoice, $payment) {
            return $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $request['amount'] === $invoice->total_minor
                && $request['currency'] === 'KES'
                && $request['reference'] === $payment->json('data.id')
                && $request['callback_url'] === 'https://app.example.com/settings/subscription/paystack-return'
                && $request['email'] === $this->owner->email;
        });
        $this->assertSame('paystack', $this->inOperator(fn () => PaymentIntent::query()->sole()->driver));
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
    }

    public function test_a_signed_webhook_verifies_with_paystack_and_marks_the_invoice_paid_and_saves_the_card_without_numbers(): void
    {
        [$invoice, $reference] = $this->started();
        $this->fakeVerify($reference, $invoice->total_minor);

        $this->webhook($reference)->assertOk();

        $this->assertSame('paid', $this->operatorInvoice($invoice->operator_invoice_id)->status);
        $this->assertSame('paid', $this->inTenant(fn () => $invoice->fresh()->status));
        $this->assertSame('succeeded', $this->inTenant(fn () => BillingPayment::query()->findOrFail($reference)->status));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), '/transaction/verify/'.$reference));

        // The card: last4, brand and expiry from the authorization only; renewals will use it.
        $method = $this->inTenant(fn () => BillingPaymentMethod::query()->sole());
        $this->assertSame(['card', 'card_aggregator', 'Visa •••• 4081 · 12/30', true, true], [$method->kind, $method->provider, $method->label, $method->auto_renew, $method->is_default]);
        $this->assertSame('AUTH_test_code', $method->token);
        $this->assertArrayNotHasKey('token', $this->getJson('/api/v1/subscription/payment-methods', $this->headersFor())->assertOk()->json('data.0'));
        $this->assertSame($method->id, $this->inTenant(fn () => BillingPayment::query()->findOrFail($reference)->payment_method_id));
    }

    public function test_a_webhook_with_a_bad_or_missing_signature_is_refused_and_asks_paystack_nothing(): void
    {
        [$invoice, $reference] = $this->started();
        $this->fake([]);
        $body = $this->event($reference);

        $this->callWebhook($body, 'f'.str_repeat('0', 127))->assertUnauthorized();
        $this->callWebhook($body, null)->assertUnauthorized();
        $this->callWebhook($body, hash_hmac('sha512', $body, 'another-key'))->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
    }

    public function test_a_repeated_webhook_changes_nothing_the_second_time(): void
    {
        [$invoice, $reference] = $this->started();
        $this->fakeVerify($reference, $invoice->total_minor);

        $this->webhook($reference)->assertOk();
        $this->webhook($reference)->assertOk();

        $this->assertSame(1, count(array_filter($this->ownerNotices(), fn ($t) => $t === 'core.subscription.payment_received')));
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.billing_invoice.paid')->count()));
        $this->assertSame(1, $this->inTenant(fn () => BillingPaymentMethod::query()->count()));
        $this->assertSame(1, $this->inOperator(fn () => PaymentIntent::query()->count()));
    }

    public function test_an_amount_that_is_not_the_invoices_never_marks_it_paid(): void
    {
        [$invoice, $reference] = $this->started();
        $this->fakeVerify($reference, $invoice->total_minor - 100);

        $this->webhook($reference)->assertOk();

        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
        $intent = $this->inOperator(fn () => PaymentIntent::query()->sole());
        $this->assertSame(['failed', 'amount_mismatch'], [$intent->status, $intent->result_code]);
        $this->assertSame('failed', $this->inTenant(fn () => BillingPayment::query()->findOrFail($reference)->status));
        $this->assertSame(0, $this->inTenant(fn () => BillingPaymentMethod::query()->count()));
    }

    public function test_a_currency_that_is_not_the_invoices_never_marks_it_paid(): void
    {
        [$invoice, $reference] = $this->started();
        $this->fakeVerify($reference, $invoice->total_minor, currency: 'USD');

        $this->webhook($reference)->assertOk();

        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
        $this->assertSame('failed', $this->inOperator(fn () => PaymentIntent::query()->sole()->status));
    }

    public function test_a_verify_that_fails_or_does_not_say_success_leaves_the_invoice_open(): void
    {
        [$invoice, $reference] = $this->started();

        // Paystack is down: nothing is known, nothing is paid.
        $this->fake(['api.paystack.co/transaction/verify/*' => Http::response('', 503)]);
        $this->webhook($reference)->assertOk();
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
        $this->assertContains($this->inOperator(fn () => PaymentIntent::query()->sole()->status), ['pending', 'unknown']);

        // Paystack says the payer abandoned the page: still open.
        $this->fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'abandoned', 'reference' => $reference]])]);
        $this->webhook($reference)->assertOk();
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);

        // A failed charge is final and shown to the payer.
        $this->fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'reference' => $reference]])]);
        $this->webhook($reference)->assertOk();
        $this->assertSame('failed', $this->inTenant(fn () => BillingPayment::query()->findOrFail($reference)->status));
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);
    }

    public function test_the_return_page_polls_our_api_which_verifies_and_the_browser_alone_pays_nothing(): void
    {
        [$invoice, $reference] = $this->started();

        $this->fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'ongoing', 'reference' => $reference]])]);
        $this->getJson("/api/v1/subscription/payments/{$reference}", $this->headersFor())->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame('open', $this->operatorInvoice($invoice->operator_invoice_id)->status);

        $this->fakeVerify($reference, $invoice->total_minor);
        $this->getJson("/api/v1/subscription/payments/{$reference}", $this->headersFor())->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('paid', $this->operatorInvoice($invoice->operator_invoice_id)->status);
    }

    public function test_a_webhook_for_an_unknown_reference_or_another_event_is_acknowledged_and_does_nothing(): void
    {
        $this->started();
        $this->fake([]);

        $this->webhook('019a0000-0000-7000-8000-00000000abcd')->assertOk();
        $this->callWebhook((string) json_encode(['event' => 'charge.failed', 'data' => ['reference' => 'x']]), null, sign: true)->assertOk();
        $this->callWebhook('not json', null, sign: true)->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_saved_card_is_charged_for_a_renewal_with_its_authorization_and_verified_before_the_invoice_is_paid(): void
    {
        [$first, $reference] = $this->started();
        $this->fakeVerify($reference, $first->total_minor);
        $this->webhook($reference)->assertOk();

        // The renewal charges the saved card by itself; Paystack's verify decides.
        $amounts = [];
        $this->fake([
            'api.paystack.co/transaction/charge_authorization' => function (Request $request) use (&$amounts) {
                $amounts[] = [$request['authorization_code'], $request['amount'], $request['currency'], $request['email']];

                return Http::response(['status' => true, 'data' => ['status' => 'success', 'reference' => $request['reference']]]);
            },
            'api.paystack.co/transaction/verify/*' => function (Request $request) use (&$amounts) {
                return Http::response(['status' => true, 'data' => [
                    'status' => 'success', 'id' => 4099260517, 'amount' => $amounts[0][1], 'currency' => 'KES',
                    'reference' => basename($request->url()), 'channel' => 'card',
                    'authorization' => ['authorization_code' => 'AUTH_test_code', 'reusable' => true, 'channel' => 'card', 'last4' => '4081', 'brand' => 'visa', 'exp_month' => '12', 'exp_year' => '2030'],
                ]]);
            },
        ]);
        $this->cycle('2026-12-16 09:00:00');

        $renewal = collect($this->invoices())->firstWhere('kind', 'renewal');
        $this->assertNotNull($renewal);
        $this->assertSame([['AUTH_test_code', $renewal->total_minor, 'KES', $this->owner->email]], $amounts);
        $this->assertSame('paid', $renewal->status);
        // The same card again: no second saved method.
        $this->assertSame(1, $this->inTenant(fn () => BillingPaymentMethod::query()->count()));
    }

    public function test_pay_needs_the_operators_paystack_key_and_the_key_is_never_in_an_answer(): void
    {
        config(['payments.paystack.secret_key' => null]);
        $this->inOperator(fn () => PaymentMethod::query()->where('provider', 'card_aggregator')->get()->each(fn ($m) => $m->fill(['secrets' => [], 'active' => false])->save()));
        $invoice = $this->subscribed();

        $response = $this->postJson("/api/v1/subscription/invoices/{$invoice->id}/pay", ['provider' => 'card_aggregator'], $this->headersFor());

        $response->assertStatus(422);
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertFalse(PaystackProvider::configured());
    }

    /** Http::fake adds stubs after the earlier ones, where the first match wins: start from a clean factory. */
    private function fake(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    private function subscribed(): BillingInvoice
    {
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');

        return $this->inTenant(fn () => BillingInvoice::query()->findOrFail($invoice));
    }

    /** @return array{0: BillingInvoice, 1: string} an invoice and the reference of its Paystack checkout */
    private function started(): array
    {
        $this->fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'ac1', 'reference' => 'x']])]);
        $invoice = $this->subscribed();
        $reference = $this->postJson("/api/v1/subscription/invoices/{$invoice->id}/pay", ['provider' => 'card_aggregator'], $this->headersFor())->assertCreated()->json('data.id');

        return [$invoice, $reference];
    }

    private function fakeVerify(string $reference, int $amount, string $currency = 'KES'): void
    {
        $this->fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'id' => 4099260516, 'amount' => $amount, 'currency' => $currency, 'reference' => $reference, 'channel' => 'card',
            'authorization' => ['authorization_code' => 'AUTH_test_code', 'reusable' => true, 'channel' => 'card', 'last4' => '4081', 'brand' => 'visa', 'exp_month' => '12', 'exp_year' => '2030'],
        ]])]);
    }

    private function event(string $reference): string
    {
        return (string) json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference, 'status' => 'success']]);
    }

    private function webhook(string $reference): TestResponse
    {
        return $this->callWebhook($this->event($reference), null, sign: true);
    }

    private function callWebhook(string $body, ?string $signature, bool $sign = false): TestResponse
    {
        $signature = $sign ? hash_hmac('sha512', $body, self::KEY) : $signature;

        return $this->call('POST', '/api/v1/billing/paystack/webhook', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], fn ($v) => $v !== null), $body);
    }
}
