<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\CallbackTokens;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\PaymentProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * MD-04, NFR-06, AUD-01: a DRC business's till takes mobile money through its
 * own pawaPay account (the `*_cd` providers). The charge is a payment intent
 * like M-Pesa STK: pending, then completed by pawaPay's answer; the
 * business's token is its own, an encrypted secret; callbacks only name the
 * deposit and pawaPay is asked again.
 */
class PawapayPosTest extends TestCase
{
    use BuildsPayments, RefreshTenantDatabase;

    private const BUSINESS_TOKEN = 'test-only-business-token';

    private PaymentMethod $vodacom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPayments();
        config(['payments.pawapay.base_url' => 'https://api.sandbox.pawapay.io', 'payments.pawapay.api_token' => 'test-only-operator-token', 'payments.pawapay.verify_signatures' => false]);

        $this->vodacom = $this->inTenant(fn () => PaymentMethod::create([
            'company_id' => $this->acme->id, 'type' => 'mobile_money', 'name' => 'Vodacom M-Pesa', 'currency' => null,
            'provider' => 'vodacom_mpesa_cd', 'settings' => [], 'secrets' => ['api_token' => self::BUSINESS_TOKEN], 'active' => true, 'position' => 50,
        ]));
    }

    public function test_every_cd_mobile_money_provider_uses_the_pawapay_driver_not_manual(): void
    {
        foreach (['vodacom_mpesa_cd', 'airtel_money_cd', 'orange_money_cd'] as $provider) {
            $this->assertSame('pawapay', config("payment_providers.{$provider}.driver"));
            $this->assertSame(['api_token'], config("payment_providers.{$provider}.secrets"));
        }
        $this->assertSame('pawapay', $this->inTenant(fn () => app(PaymentProviderRegistry::class)->driverName($this->vodacom)));
        // Afrimoney is not a pawaPay provider here: unchanged.
        $this->assertSame('manual', config('payment_providers.afrimoney_cd.driver'));
    }

    public function test_a_charge_is_a_pending_intent_that_asks_pawapay_with_the_businesss_own_token_and_exact_usd_amount(): void
    {
        $this->fakePawapay();

        $response = $this->charge('4850')->assertCreated();

        $response->assertJsonPath('data.status', 'pending');
        $this->assertStringNotContainsString('0813456789', $response->getContent());
        $this->assertStringNotContainsString('243813456789', $response->getContent());
        $deposit = $this->deposit();
        $this->assertSame(['48.50', 'USD', 'VODACOM_MPESA_COD', '243813456789'], [$deposit['amount'], $deposit['currency'], $deposit['payer']['accountDetails']['provider'], $deposit['payer']['accountDetails']['phoneNumber']]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/deposits') && $request->hasHeader('Authorization', 'Bearer '.self::BUSINESS_TOKEN));
        $intent = $this->inTenant(fn () => PaymentIntent::query()->sole());
        $this->assertSame(['pawapay', 'stk', 'vodacom_mpesa_cd', $deposit['depositId']], [$intent->driver, $intent->mode, $intent->provider, $intent->provider_request_id]);
    }

    public function test_the_same_id_charges_once(): void
    {
        $this->fakePawapay();
        $id = (string) Str::uuid7();

        $this->charge('4850', ['id' => $id])->assertCreated();
        $this->charge('4850', ['id' => $id])->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, count(array_filter(Http::recorded()->all(), fn ($pair) => str_ends_with($pair[0]->url(), '/v2/deposits') && $pair[0]->method() === 'POST')));
    }

    public function test_the_timer_asks_pawapay_and_completes_a_deposit_that_was_approved_on_the_phone(): void
    {
        $this->fakePawapay();
        $id = $this->charge('4850')->json('data.id');
        $this->fakePawapay(status: 'COMPLETED', amount: '48.50');

        $this->travelTo(CarbonImmutable::now()->addMinutes(2));
        $this->inTenant(fn () => app(PaymentIntents::class)->processTimers(CarbonImmutable::now()));

        $this->getJson("/api/v1/payments/intents/{$id}", $this->tillHeaders())->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $id)->where('after->status', 'succeeded')->exists()));
    }

    public function test_a_declined_deposit_is_cancelled_and_a_failed_one_is_failed(): void
    {
        $this->fakePawapay();
        $declined = $this->charge('4850', ['reference' => (string) Str::uuid7()])->json('data.id');
        $this->fakePawapay(status: 'FAILED', amount: '48.50', failure: 'PAYMENT_NOT_APPROVED');
        $this->callbackFor($declined, 'pawapay-deposit')->assertOk();
        $this->getJson("/api/v1/payments/intents/{$declined}", $this->tillHeaders())->assertJsonPath('data.status', 'cancelled');

        $this->fakePawapay();
        $failed = $this->charge('4850', ['reference' => (string) Str::uuid7()])->json('data.id');
        $this->fakePawapay(status: 'FAILED', amount: '48.50', failure: 'INSUFFICIENT_BALANCE');
        $this->callbackFor($failed, 'pawapay-deposit')->assertOk();
        $this->getJson("/api/v1/payments/intents/{$failed}", $this->tillHeaders())->assertJsonPath('data.status', 'failed');
    }

    public function test_a_callback_is_rechecked_with_pawapay_and_a_duplicate_changes_nothing(): void
    {
        $this->fakePawapay();
        $id = $this->charge('4850')->json('data.id');

        // The callback claims it is paid; pawaPay says it is still processing.
        $this->fakePawapay(status: 'PROCESSING', amount: '48.50');
        $this->callbackFor($id, 'pawapay-deposit')->assertOk();
        $this->getJson("/api/v1/payments/intents/{$id}", $this->tillHeaders())->assertJsonPath('data.status', 'pending');

        $this->fakePawapay(status: 'COMPLETED', amount: '48.50');
        $this->callbackFor($id, 'pawapay-deposit')->assertOk();
        $this->callbackFor($id, 'pawapay-deposit')->assertOk();
        $this->getJson("/api/v1/payments/intents/{$id}", $this->tillHeaders())->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $id)->where('after->status', 'succeeded')->count()));
    }

    public function test_an_amount_pawapay_reports_that_is_not_the_charge_is_never_a_success(): void
    {
        $this->fakePawapay();
        $id = $this->charge('4850')->json('data.id');
        $this->fakePawapay(status: 'COMPLETED', amount: '48.00');

        $this->callbackFor($id, 'pawapay-deposit')->assertOk();

        $intent = $this->inTenant(fn () => PaymentIntent::query()->findOrFail($id));
        $this->assertSame(['failed', 'amount_mismatch'], [$intent->status, $intent->result_code]);
    }

    public function test_a_callback_needs_the_methods_token_and_pawapay_callbacks_are_not_daraja_ones(): void
    {
        $this->fakePawapay();
        $id = $this->charge('4850')->json('data.id');
        $token = $this->callbackToken($this->vodacom);

        $this->postJson('/api/v1/payments/callbacks/'.Str::random(CallbackTokens::LENGTH).'/pawapay-deposit', ['depositId' => $id])->assertNotFound();
        // The M-Pesa (Daraja) method's token does not take pawaPay callbacks, and the other way round.
        $this->providerCallback('pawapay-deposit', ['depositId' => $id], $this->callbackToken())->assertNotFound();
        $this->postJson("/api/v1/payments/callbacks/{$token}/stk", [])->assertNotFound();
        // With signatures on, an unsigned callback is refused.
        config(['payments.pawapay.verify_signatures' => true]);
        $this->postJson("/api/v1/payments/callbacks/{$token}/pawapay-deposit", ['depositId' => $id])->assertUnauthorized();
    }

    public function test_a_pawapay_method_shows_its_own_two_callback_urls_and_an_mpesa_method_its_own(): void
    {
        $pawapay = $this->getJson("/api/v1/payment-methods/{$this->vodacom->id}/callbacks", $this->headersFor())->assertOk()->json('data.urls');
        $mpesa = $this->getJson("/api/v1/payment-methods/{$this->mpesa->id}/callbacks", $this->headersFor())->assertOk()->json('data.urls');

        $this->assertSame(['pawapay-deposit', 'pawapay-refund'], array_keys($pawapay));
        $this->assertStringContainsString('/api/v1/payments/callbacks/', $pawapay['pawapay-deposit']);
        $this->assertArrayNotHasKey('pawapay-deposit', $mpesa);
        $this->assertArrayHasKey('stk', $mpesa);
    }

    public function test_a_server_error_keeps_the_intent_open_and_shows_neither_the_phone_nor_the_token(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.sandbox.pawapay.io/v2/predict-provider' => Http::response(['country' => 'COD', 'provider' => 'VODACOM_MPESA_COD', 'phoneNumber' => '243813456789']), 'api.sandbox.pawapay.io/v2/deposits' => Http::response('boom 243813456789 '.self::BUSINESS_TOKEN, 500)]);

        $response = $this->charge('4850')->assertCreated();

        $this->assertSame('unknown', $response->json('data.status'));
        $this->assertStringNotContainsString('243813456789', $response->getContent());
        $this->assertStringNotContainsString(self::BUSINESS_TOKEN, $response->getContent());
        $intent = $this->inTenant(fn () => PaymentIntent::query()->sole());
        $this->assertStringNotContainsString(self::BUSINESS_TOKEN, json_encode($intent->getAttributes()));
        $this->assertStringNotContainsString('243813456789', (string) $intent->result_message);
    }

    public function test_a_business_without_its_token_cannot_borrow_the_operators(): void
    {
        $this->inTenant(fn () => $this->vodacom->fill(['secrets' => []])->save());
        $this->fakePawapay();

        $response = $this->charge('4850')->assertCreated();

        $this->assertContains($response->json('data.status'), ['unknown', 'failed']);
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-only-operator-token'));
    }

    private function charge(string $amountMinor, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/payments/intents', [
            'payment_method_id' => $this->vodacom->id, 'mode' => 'stk', 'amount_minor' => $amountMinor, 'currency' => 'USD', 'phone' => '0813456789',
            'reference_type' => 'pos.sale', 'user_id' => $this->owner->id, 'reference' => '019a0000-0000-7000-8000-0000000000aa', ...$extra,
        ], $this->tillHeaders());
    }

    private function callbackFor(string $intentId, string $kind): TestResponse
    {
        $depositId = $this->inTenant(fn () => PaymentIntent::query()->findOrFail($intentId)->provider_request_id);
        $token = $this->callbackToken($this->vodacom);

        return $this->postJson("/api/v1/payments/callbacks/{$token}/{$kind}", ['depositId' => $depositId, 'status' => 'COMPLETED']);
    }

    private function fakePawapay(?string $status = null, string $amount = '48.50', ?string $failure = null): void
    {
        Http::swap(new Factory);
        Http::fake([
            'api.sandbox.pawapay.io/v2/predict-provider' => Http::response(['country' => 'COD', 'provider' => 'VODACOM_MPESA_COD', 'phoneNumber' => '243813456789']),
            'api.sandbox.pawapay.io/v2/deposits' => fn (Request $request) => Http::response(['depositId' => $request['depositId'], 'status' => 'ACCEPTED', 'nextStep' => 'FINAL_STATUS', 'created' => '2026-11-01T08:00:00Z']),
            'api.sandbox.pawapay.io/v2/deposits/*' => fn (Request $request) => Http::response(['status' => 'FOUND', 'data' => array_filter([
                'depositId' => basename($request->url()), 'status' => $status ?? 'ACCEPTED', 'amount' => $amount, 'currency' => 'USD', 'country' => 'COD',
                'payer' => ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => '243813456789', 'provider' => 'VODACOM_MPESA_COD']],
                'providerTransactionId' => 'PP-TXN-1', 'failureReason' => $failure === null ? null : ['failureCode' => $failure, 'failureMessage' => 'x'],
            ])]),
        ]);
    }

    private function deposit(): array
    {
        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/v2/deposits')) {
                return $request->data();
            }
        }

        $this->fail('No deposit was requested.');
    }
}
