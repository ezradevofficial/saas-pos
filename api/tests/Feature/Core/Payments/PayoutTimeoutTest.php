<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Concept note 7.1: a B2C refund whose request got no usable answer (a
// time-out, a 5xx without Daraja's error body) may still pay the customer.
// It stays `unknown` (counted against the original payment) until its
// result callback, which finds it by the intent's own id, or until
// `payments.payout_give_up_hours`.
class PayoutTimeoutTest extends TestCase
{
    use BuildsPayments, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments(['initiator_name' => 'apiop', 'b2c_shortcode' => '600000'], ['security_credential' => 'sec-cred-'.self::SECRET]);
    }

    private function intent(string $id): PaymentIntent
    {
        return $this->inTenant(fn () => PaymentIntent::query()->findOrFail($id));
    }

    /** A paid STK push, then a payout of KES 500.00 against it. */
    private function payoutAfter(mixed $b2cAnswer): PaymentIntent
    {
        $this->fakeDaraja(['sandbox.safaricom.co.ke/mpesa/b2c/*' => $b2cAnswer]);
        $sale = $this->intent($this->push()->assertCreated()->json('data.id'));
        $this->providerCallback('stk', $this->stkCallback($sale->provider_checkout_id, 0))->assertOk();

        return $this->inTenant(fn () => app(PaymentIntents::class)->payout($this->mpesa->fresh(), $this->intent($sale->id), [
            'id' => (string) Str::uuid7(), 'amount_minor' => 50000, 'currency' => 'KES', 'reference_type' => 'pos.refund', 'reference' => (string) Str::uuid7(),
        ]));
    }

    public function test_a_timed_out_payout_stays_unknown_and_its_late_result_completes_it(): void
    {
        $sent = null;
        $refund = $this->payoutAfter(function (Request $request) use (&$sent) {
            $sent = $request['OriginatorConversationID'];

            throw new ConnectionException('read timed out');
        });

        // Sent with the intent id as OriginatorConversationID, stored before the call.
        $this->assertSame($refund->id, $sent);
        $this->assertSame(['unknown', 'no_answer_from_provider', $refund->id], [$refund->status, $refund->result_code, $refund->provider_request_id]);
        $this->assertTrue($refund->expires_at->greaterThan(now()->addHours(23)));

        // Still open a minute later: no status query for payouts, the result callback is awaited.
        Artisan::call('payments:process-timers', ['--at' => CarbonImmutable::now()->addMinutes(5)->toIso8601String()]);
        $this->assertSame('unknown', $this->intent($refund->id)->status);

        // The result names the intent by its own id (the request id is gone here, to prove it).
        $this->inTenant(fn () => DB::table('payment_intents')->where('id', $refund->id)->update(['provider_request_id' => null]));
        $this->providerCallback('b2c-result', ['Result' => [
            'ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
            'OriginatorConversationID' => $refund->id, 'ConversationID' => 'AG_B2C_LATE', 'TransactionID' => 'QJK3LATE01',
        ]])->assertOk();

        $this->assertSame(['succeeded', 'QJK3LATE01'], [$this->intent($refund->id)->status, $this->intent($refund->id)->provider_receipt]);
    }

    public function test_a_payout_after_a_bare_server_error_is_unknown_and_times_out_after_the_give_up_hours(): void
    {
        $refund = $this->payoutAfter(Http::response(['message' => 'upstream'], 503));

        $this->assertSame('unknown', $refund->status);

        Artisan::call('payments:process-timers', ['--at' => CarbonImmutable::now()->addHours(23)->toIso8601String()]);
        $this->assertSame('unknown', $this->intent($refund->id)->status);

        Artisan::call('payments:process-timers', ['--at' => CarbonImmutable::now()->addHours(25)->toIso8601String()]);
        $this->assertSame(['timeout', 'no_result'], [$this->intent($refund->id)->status, $this->intent($refund->id)->result_code]);
    }

    public function test_a_refusal_with_daraja_s_error_body_still_fails_at_once(): void
    {
        $refund = $this->payoutAfter(Http::response(['errorCode' => '401.002.01', 'errorMessage' => 'Invalid initiator'], 500));

        $this->assertSame(['failed', '401.002.01'], [$refund->status, $refund->result_code]);
    }
}
