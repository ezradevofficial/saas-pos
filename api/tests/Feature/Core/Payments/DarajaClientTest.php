<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Payments\Models\PaymentIntent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Concept note 7.1: the Daraja adapter's requests (OAuth token caching,
// Lipa na M-Pesa Online password and payload) against faked HTTP.
class DarajaClientTest extends TestCase
{
    use BuildsPayments, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
    }

    /** @return list<Request> */
    private function sent(string $fragment): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $fragment))->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_the_oauth_token_is_fetched_once_and_cached(): void
    {
        $this->fakeDaraja();

        $this->push()->assertCreated();
        $this->push(extra: ['reference' => '019a0000-0000-7000-8000-000000000002'])->assertCreated();

        $this->assertCount(1, $this->sent('/oauth/v1/generate'));
        $oauth = $this->sent('/oauth/v1/generate')[0];
        $this->assertSame('Basic '.base64_encode('ck-'.self::SECRET.':cs-'.self::SECRET), $oauth->header('Authorization')[0]);
        $this->assertSame('client_credentials', $oauth['grant_type'] ?? null);

        $pushes = $this->sent('/mpesa/stkpush/v1/processrequest');
        $this->assertCount(2, $pushes);
        $this->assertSame('Bearer tok-daraja-1', $pushes[1]->header('Authorization')[0]);
    }

    public function test_an_expired_token_is_fetched_again_once(): void
    {
        $this->fakeDaraja([
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => Http::sequence()
                ->push(['errorCode' => '404.001.03', 'errorMessage' => 'Invalid Access Token'], 401)
                ->push(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_retry', 'ResponseCode' => '0']),
        ]);

        $this->push()->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertCount(2, $this->sent('/oauth/v1/generate'));
        $this->assertCount(2, $this->sent('/mpesa/stkpush/'));
    }

    public function test_the_stk_push_payload_follows_lipa_na_mpesa_online(): void
    {
        $this->fakeDaraja();
        CarbonImmutable::setTestNow('2026-10-09T07:15:30Z');

        $intent = $this->push('150000', '+254 712 345 678')->assertCreated()->json('data');

        $body = $this->sent('/mpesa/stkpush/')[0]->data();
        $this->assertSame('174379', $body['BusinessShortCode']);
        // Nairobi is UTC+3.
        $this->assertSame('20261009101530', $body['Timestamp']);
        $this->assertSame(base64_encode('174379pk-'.self::SECRET.'20261009101530'), $body['Password']);
        $this->assertSame('CustomerPayBillOnline', $body['TransactionType']);
        $this->assertSame(1500, $body['Amount']);
        $this->assertSame('254712345678', $body['PartyA']);
        $this->assertSame('254712345678', $body['PhoneNumber']);
        $this->assertSame('174379', $body['PartyB']);
        $this->assertMatchesRegularExpression('#^https://api\.example\.com/api/v1/payments/callbacks/[A-Za-z0-9]{48}/stk$#', $body['CallBackURL']);
        $this->assertStringNotContainsStringIgnoringCase('mpesa', $body['CallBackURL']);
        $this->assertSame($intent['account_reference'], $body['AccountReference']);
        $this->assertLessThanOrEqual(12, strlen($body['AccountReference']));
        $this->assertLessThanOrEqual(13, strlen($body['TransactionDesc']));

        // The till sees a masked phone, never the number.
        $this->assertSame('2547*****678', $intent['phone']);
        $this->assertSame('pending', $intent['status']);
        CarbonImmutable::setTestNow();
    }

    public function test_a_till_number_pays_to_the_till_with_buy_goods(): void
    {
        $this->inTenant(fn () => $this->mpesa->fill(['settings' => ['shortcode' => '174379', 'transaction_type' => 'till', 'till_number' => '5123456']])->save());
        $this->fakeDaraja();

        $this->push()->assertCreated();

        $body = $this->sent('/mpesa/stkpush/')[0]->data();
        $this->assertSame('CustomerBuyGoodsOnline', $body['TransactionType']);
        $this->assertSame('5123456', $body['PartyB']);
        $this->assertSame('174379', $body['BusinessShortCode']);
    }

    public function test_a_refused_push_fails_the_intent_with_a_safe_message(): void
    {
        $this->fakeDaraja([
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => Http::response(['requestId' => 'r-1', 'errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PhoneNumber 254712345678'], 400),
        ]);

        $response = $this->push()->assertCreated();

        $response->assertJsonPath('data.status', 'failed')->assertJsonPath('data.result_code', '400.002.02');
        $this->assertStringNotContainsString('254712345678', $response->getContent());
    }

    public function test_an_unanswered_push_stays_unknown_and_a_later_c2b_confirmation_completes_it(): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => fn () => throw new ConnectionException('read timed out'),
        ]);

        // The push may have reached the phone: never failed, kept open.
        $intent = $this->push()->assertCreated()->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.result_code', 'no_answer_from_provider')->json('data');

        // The customer paid: the Paybill's confirmation carries the push's account reference.
        $this->providerCallback('c2b-confirm', $this->c2bConfirmation('QJK3LOST01', '1500.00', $intent['account_reference']))->assertOk();
        $this->getJson("/api/v1/payments/intents/{$intent['id']}", $this->tillHeaders())->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.receipt', 'QJK3LOST01');
    }

    public function test_an_unknown_push_that_never_answers_times_out(): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => fn () => throw new ConnectionException('read timed out'),
        ]);
        $id = $this->push()->assertCreated()->json('data.id');

        Artisan::call('payments:process-timers', ['--at' => CarbonImmutable::now()->addSeconds(60)->toIso8601String()]);
        $this->assertSame('unknown', $this->inTenant(fn () => PaymentIntent::query()->findOrFail($id)->status));
        Artisan::call('payments:process-timers', ['--at' => CarbonImmutable::now()->addSeconds(400)->toIso8601String()]);
        $this->assertSame('timeout', $this->inTenant(fn () => PaymentIntent::query()->findOrFail($id)->status));
    }

    public function test_whole_shillings_kes_only_and_a_valid_phone_are_required(): void
    {
        $this->fakeDaraja();

        $this->push('150050')->assertUnprocessable()->assertJsonPath('code', 'whole_units');
        $this->push(extra: ['currency' => 'USD'])->assertUnprocessable()->assertJsonPath('code', 'currency_not_supported');
        $this->push(phone: '0612')->assertUnprocessable()->assertJsonPath('code', 'phone_invalid');
        $this->assertSame(0, $this->inTenant(fn () => PaymentIntent::query()->count()));
        Http::assertNothingSent();
    }
}
