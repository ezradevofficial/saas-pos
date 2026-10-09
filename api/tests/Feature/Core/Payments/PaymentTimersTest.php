<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Payments\Events\PaymentIntentSettled;
use App\Core\Payments\Jobs\ProcessPaymentTimers;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\Models\PaymentReceipt;
use App\Core\Payments\PaymentIntents;
use App\Core\Tenancy\DueTenants;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;

// Concept note 7.1: STK pushes the customer never answered are checked
// with Daraja (STK query) and timed out; manual codes are checked with a
// transaction status query whose answer arrives by callback. The
// scheduler finds tenants through a security-definer function, never the
// owner connection (ADR 002).
class PaymentTimersTest extends TestCase
{
    use BuildsPayments, RefreshTenantDatabase, WithoutOwnerConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments(['initiator_name' => 'apiop'], ['security_credential' => 'sec-cred']);
    }

    private function intent(string $id): PaymentIntent
    {
        return $this->inTenant(fn () => PaymentIntent::query()->findOrFail($id));
    }

    private function runTimers(CarbonImmutable $at): void
    {
        Artisan::call('payments:process-timers', ['--at' => $at->toIso8601String()]);
    }

    public function test_an_unanswered_push_is_queried_then_paid(): void
    {
        $this->fakeDaraja([
            'sandbox.safaricom.co.ke/mpesa/stkpushquery/*' => Http::response(['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']),
        ]);
        $intent = $this->intent($this->push()->assertCreated()->json('data.id'));

        $this->withoutOwnerConnection();
        // Not due yet: nothing is asked.
        $this->runTimers(CarbonImmutable::now()->addSeconds(30));
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'stkpushquery'))->count());

        $this->runTimers(CarbonImmutable::now()->addSeconds(100));

        $query = Http::recorded(fn (Request $r) => str_contains($r->url(), 'stkpushquery'))->first()[0]->data();
        $this->assertSame($intent->provider_checkout_id, $query['CheckoutRequestID']);
        $this->assertSame('succeeded', $this->intent($intent->id)->status);

        // The late callback still brings the receipt.
        $this->providerCallback('stk', $this->stkCallback($intent->provider_checkout_id, 0, 'QJK3LATE01'))->assertOk();
        $this->assertSame('QJK3LATE01', $this->intent($intent->id)->provider_receipt);
    }

    public function test_a_run_starts_no_provider_call_that_would_outlast_it(): void
    {
        $this->fakeDaraja();
        $this->push()->assertCreated();

        config(['payments.run_seconds' => 20]); // less than one call (timeout 15 + 5)
        $this->assertSame(0, $this->inTenant(fn () => app(PaymentIntents::class)->processTimers(CarbonImmutable::now()->addSeconds(100))));
        config(['payments.run_seconds' => 55]);
        $this->assertSame(1, $this->inTenant(fn () => app(PaymentIntents::class)->processTimers(CarbonImmutable::now()->addSeconds(100))));
    }

    public function test_a_push_still_processing_waits_then_times_out(): void
    {
        $this->fakeDaraja();
        $intent = $this->intent($this->push()->assertCreated()->json('data.id'));

        $this->runTimers(CarbonImmutable::now()->addSeconds(100));
        $this->assertSame('pending', $this->intent($intent->id)->status);

        $this->runTimers(CarbonImmutable::now()->addSeconds(400));
        $timedOut = $this->intent($intent->id);
        $this->assertSame('timeout', $timedOut->status);
        $this->assertSame('no_answer', $timedOut->result_code);

        // A paid result arriving late still completes it (the POS hears of it).
        Event::fake([PaymentIntentSettled::class]);
        $this->providerCallback('stk', $this->stkCallback($intent->provider_checkout_id, 0, 'QJK3LATE02'))->assertOk();
        $paid = $this->intent($intent->id);
        $this->assertSame(['succeeded', 'QJK3LATE02'], [$paid->status, $paid->provider_receipt]);
        Event::assertDispatched(PaymentIntentSettled::class, fn (PaymentIntentSettled $e) => $e->intentId === $intent->id);
        $this->inTenant(fn () => $this->assertSame(0, PaymentReceipt::query()->count()));
    }

    public function test_a_manual_code_is_checked_and_verified_by_the_status_result(): void
    {
        $this->fakeDaraja();
        $id = $this->postJson('/api/v1/payments/intents', [
            'payment_method_id' => $this->mpesa->id, 'mode' => 'manual', 'amount_minor' => '150000', 'currency' => 'KES',
            'receipt' => 'QJK3OFFLN1', 'reference_type' => 'pos.sale', 'reference' => (string) Str::uuid7(), 'user_id' => $this->owner->id,
        ], $this->tillHeaders())->assertCreated()->json('data.id');

        $this->runTimers(CarbonImmutable::now()->addMinutes(31));

        $status = Http::recorded(fn (Request $r) => str_contains($r->url(), 'transactionstatus'))->first()[0]->data();
        $this->assertSame('QJK3OFFLN1', $status['TransactionID']);
        $this->assertSame('TransactionStatusQuery', $status['CommandID']);
        $this->assertSame('apiop', $status['Initiator']);
        $this->assertStringEndsWith('/status-result', $status['ResultURL']);
        $this->assertSame('AG_TS_1', $this->intent($id)->verification_ref);

        // Asked once: the next run does not ask again.
        $this->runTimers(CarbonImmutable::now()->addMinutes(40));
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'transactionstatus'))->count());

        $this->providerCallback('status-result', ['Result' => [
            'ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
            'OriginatorConversationID' => 'orig-ts-1', 'ConversationID' => 'AG_TS_1', 'TransactionID' => 'QJK3OFFLN1',
            'ResultParameters' => ['ResultParameter' => [
                ['Key' => 'ReceiptNo', 'Value' => 'QJK3OFFLN1'], ['Key' => 'Amount', 'Value' => 1000],
                ['Key' => 'TransactionStatus', 'Value' => 'Completed'], ['Key' => 'DebitPartyName', 'Value' => '254712345678 - Jane Doe'],
            ]],
        ]])->assertOk();

        $checked = $this->intent($id);
        $this->assertSame('mismatch', $checked->verification);
        $this->assertSame('amount_mismatch', $checked->result_code);
        $this->assertStringNotContainsString('Jane', json_encode($checked->provider_data));
    }

    public function test_the_scheduler_finds_only_tenants_with_due_intents_without_the_owner_connection(): void
    {
        $this->fakeDaraja();
        $this->push()->assertCreated();
        $other = $this->otherTenant();

        $this->withoutOwnerConnection();
        $due = app(DueTenants::class);
        $this->assertSame([], $due->withDuePaymentIntents(CarbonImmutable::now()));
        $this->assertSame([$this->owner->tenant_id], $due->withDuePaymentIntents(CarbonImmutable::now()->addMinutes(2)));
        $this->assertNotContains($other['user']->tenant_id, $due->withDuePaymentIntents(CarbonImmutable::now()->addDay()));

        Bus::fake();
        $this->runTimers(CarbonImmutable::now()->addMinutes(2));
        Bus::assertDispatched(ProcessPaymentTimers::class, fn (ProcessPaymentTimers $job) => $job->tenantId === $this->owner->tenant_id && $job->queue === 'payments');
        Bus::assertDispatchedTimes(ProcessPaymentTimers::class, 1);
    }
}
