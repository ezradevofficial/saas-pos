<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\CallbackTokens;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\Models\PaymentReceipt;
use App\Core\Payments\PaymentIntents;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Concept note 7.1: Daraja callbacks. Public, so each is checked: the
// token names the tenant and method (forged: 404), the address must be
// Safaricom's (403), the body must be Daraja's (422), and each result is
// applied once (a repeat changes nothing). C2B confirmations verify manual
// codes or complete unanswered pushes, else wait for the back office.
class MpesaCallbackTest extends TestCase
{
    use BuildsPayments, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments(['initiator_name' => 'apiop', 'b2c_shortcode' => '600000'], ['security_credential' => 'sec-cred-'.self::SECRET]);
        $this->fakeDaraja();
    }

    private function intent(string $id): PaymentIntent
    {
        return $this->inTenant(fn () => PaymentIntent::query()->findOrFail($id));
    }

    private function pushed(): PaymentIntent
    {
        return $this->intent($this->push()->assertCreated()->json('data.id'));
    }

    private function manual(string $code = 'QJK3ABC123', string $amount = '150000'): PaymentIntent
    {
        $id = $this->postJson('/api/v1/payments/intents', [
            'payment_method_id' => $this->mpesa->id, 'mode' => 'manual', 'amount_minor' => $amount, 'currency' => 'KES',
            'receipt' => $code, 'reference_type' => 'pos.sale', 'reference' => (string) Str::uuid7(),
        ], $this->tillHeaders())->assertCreated()->json('data.id');

        return $this->intent($id);
    }

    public function test_a_paid_stk_callback_completes_the_intent_once(): void
    {
        $intent = $this->pushed();

        $this->providerCallback('stk', $this->stkCallback($intent->provider_checkout_id, 0))->assertOk()->assertExactJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        $paid = $this->intent($intent->id);
        $this->assertSame('succeeded', $paid->status);
        $this->assertSame('QJK3ABC123', $paid->provider_receipt);
        $this->assertSame('150000', (string) $paid->provider_data['amount']);
        $this->assertArrayNotHasKey('phone', $paid->provider_data);

        $this->getJson("/api/v1/payments/intents/{$intent->id}", $this->tillHeaders())->assertOk()
            ->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.receipt', 'QJK3ABC123');

        // The same callback again: accepted, nothing changes, nothing audited.
        $audits = $this->inTenant(fn () => AuditEntry::query()->count());
        $this->providerCallback('stk', $this->stkCallback($intent->provider_checkout_id, 1032))->assertOk();
        $this->assertSame('succeeded', $this->intent($intent->id)->status);
        $this->assertSame($audits, $this->inTenant(fn () => AuditEntry::query()->count()));
    }

    public function test_declined_unreachable_and_wrong_amount_callbacks(): void
    {
        $declined = $this->pushed();
        $this->providerCallback('stk', $this->stkCallback($declined->provider_checkout_id, 1032))->assertOk();
        $this->assertSame('cancelled', $this->intent($declined->id)->status);

        $unreached = $this->pushed();
        $this->providerCallback('stk', $this->stkCallback($unreached->provider_checkout_id, 1037))->assertOk();
        $this->assertSame('timeout', $this->intent($unreached->id)->status);

        $short = $this->pushed();
        $this->providerCallback('stk', $this->stkCallback($short->provider_checkout_id, 0, 'QJK3SHORT1', '1.00'))->assertOk();
        $this->assertSame('failed', $this->intent($short->id)->status);
        $this->assertSame('amount_mismatch', $this->intent($short->id)->result_code);
    }

    public function test_forged_tokens_foreign_addresses_and_bad_bodies_are_refused(): void
    {
        $intent = $this->pushed();
        $body = $this->stkCallback($intent->provider_checkout_id, 0);

        $this->providerCallback('stk', $body, token: Str::random(48))->assertNotFound();
        $this->providerCallback('stk', $body, token: 'short')->assertNotFound();
        $this->providerCallback('stk', $body, ip: '203.0.113.9')->assertForbidden();
        $this->providerCallback('stk', ['Body' => ['nothing' => true]])->assertUnprocessable();
        $this->providerCallback('unknown-kind', $body)->assertNotFound();
        $this->assertSame('pending', $this->intent($intent->id)->status);

        // A rotated token: the old URLs stop working.
        $old = $this->callbackToken();
        $this->postJson("/api/v1/payment-methods/{$this->mpesa->id}/callbacks/rotate", [], $this->headersFor())->assertOk();
        $this->providerCallback('stk', $body, token: $old)->assertNotFound();
        $this->providerCallback('stk', $body)->assertOk();
        $this->assertSame('succeeded', $this->intent($intent->id)->status);

        // With the allowlist off (local tunnels), any address is heard.
        config(['payments.mpesa.enforce_callback_ips' => false]);
        $this->providerCallback('stk', $body, ip: '203.0.113.9')->assertOk();
    }

    public function test_one_tenants_token_cannot_complete_another_tenants_intent(): void
    {
        $intent = $this->pushed();

        $other = $this->otherTenant();
        $foreignToken = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(TenantCurrencies::class)->provisionFor($other['company']);
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => app(DefaultPaymentMethods::class)->seed($other['company']));
            $method = PaymentMethod::query()->where('provider', 'mpesa_ke')->sole();

            return app(CallbackTokens::class)->token($method);
        });

        $this->providerCallback('stk', $this->stkCallback($intent->provider_checkout_id, 0), token: $foreignToken)->assertOk();
        $this->assertSame('pending', $this->intent($intent->id)->status);
    }

    public function test_c2b_confirmations_verify_manual_codes_once_and_flag_mismatches(): void
    {
        $exact = $this->manual('QJK3EXACT1');
        $short = $this->manual('QJK3SHORT2');

        $this->providerCallback('c2b-confirm', $this->c2bConfirmation('QJK3EXACT1', '1500.00'))->assertOk()->assertJsonPath('ResultCode', 0);
        $this->providerCallback('c2b-confirm', $this->c2bConfirmation('QJK3EXACT1', '1500.00'))->assertOk();
        $this->providerCallback('c2b-confirm', $this->c2bConfirmation('QJK3SHORT2', '1000.00'))->assertOk();

        $this->assertSame('verified', $this->intent($exact->id)->verification);
        $this->assertSame('mismatch', $this->intent($short->id)->verification);
        $this->inTenant(function () {
            $this->assertSame(2, PaymentReceipt::query()->count());
            $this->assertSame(0, PaymentReceipt::query()->where('status', 'unmatched')->count());
            // No payer names are kept.
            $this->assertStringNotContainsString('Jane', PaymentReceipt::query()->get()->toJson());
        });

        // A code typed after its confirmation arrived is verified at once.
        $this->providerCallback('c2b-confirm', $this->c2bConfirmation('QJK3LATER3', '1500.00'))->assertOk();
        $this->assertSame('verified', $this->manual('QJK3LATER3')->verification);
    }

    public function test_a_c2b_confirmation_completes_an_unanswered_push_by_account_reference(): void
    {
        $intent = $this->pushed();

        $this->providerCallback('c2b-confirm', $this->c2bConfirmation('QJK3PAYBL1', '1500.00', $intent->account_reference))->assertOk();

        $paid = $this->intent($intent->id);
        $this->assertSame('succeeded', $paid->status);
        $this->assertSame('QJK3PAYBL1', $paid->provider_receipt);
    }

    public function test_unmatched_money_is_listed_and_matched_in_the_back_office(): void
    {
        $this->providerCallback('c2b-confirm', $this->c2bConfirmation('QJK3LOOSE1', '1500.00', 'counter'))->assertOk();
        $intent = $this->pushed();
        $this->providerCallback('stk', $this->stkCallback($intent->provider_checkout_id, 1037))->assertOk();

        $list = $this->getJson("/api/v1/companies/{$this->acme->id}/payment-receipts", $this->headersFor())->assertOk();
        $this->assertSame(['QJK3LOOSE1'], array_column($list->json('data'), 'receipt'));
        $receipt = $list->json('data.0.id');

        // A branch manager sees payments of their branch but may not match
        // money; a cashier does not see the company's payments at all;
        // another tenant does not see the receipt.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->postJson("/api/v1/payment-receipts/{$receipt}/match", ['payment_intent_id' => $intent->id], $this->headersFor($manager))->assertForbidden();
        $this->assertSame([], $this->getJson("/api/v1/companies/{$this->acme->id}/payment-receipts", $this->headersFor($manager))->assertOk()->json('data'));
        $this->assertSame([$intent->id], array_column($this->getJson("/api/v1/companies/{$this->acme->id}/payment-intents", $this->headersFor($manager))->assertOk()->json('data'), 'id'));
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson("/api/v1/companies/{$this->acme->id}/payment-intents", $this->headersFor($cashier))->assertNotFound();
        $other = $this->otherTenant();
        $this->postJson("/api/v1/payment-receipts/{$receipt}/match", ['payment_intent_id' => $intent->id], $this->headersFor($other['user']))->assertNotFound();

        $this->postJson("/api/v1/payment-receipts/{$receipt}/match", ['payment_intent_id' => $intent->id], $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'matched')->assertJsonPath('data.payment_intent_id', $intent->id);
        $this->assertSame('succeeded', $this->intent($intent->id)->status);
        $this->postJson("/api/v1/payment-receipts/{$receipt}/match", ['payment_intent_id' => $intent->id], $this->headersFor())->assertUnprocessable();

        $this->getJson("/api/v1/companies/{$this->acme->id}/payment-intents?status=succeeded&search={$intent->account_reference}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.id', $intent->id)->assertJsonPath('data.0.phone', '2547*****678');
    }

    public function test_refunds_are_paid_back_by_b2c_to_the_original_phone(): void
    {
        $sale = $this->pushed();
        $this->providerCallback('stk', $this->stkCallback($sale->provider_checkout_id, 0))->assertOk();

        $refund = $this->inTenant(fn () => app(PaymentIntents::class)->payout($this->mpesa->fresh(), $this->intent($sale->id), [
            'id' => (string) Str::uuid7(), 'amount_minor' => 50000, 'currency' => 'KES', 'reference_type' => 'pos.refund', 'reference' => (string) Str::uuid7(),
        ]));

        $this->assertSame('pending', $refund->status);
        $b2c = Http::recorded(fn (Request $r) => str_contains($r->url(), '/mpesa/b2c/'))->first()[0]->data();
        $this->assertSame('apiop', $b2c['InitiatorName']);
        $this->assertSame('sec-cred-'.self::SECRET, $b2c['SecurityCredential']);
        $this->assertSame('600000', $b2c['PartyA']);
        $this->assertSame('254712345678', $b2c['PartyB']);
        $this->assertSame(500, $b2c['Amount']);
        $this->assertSame($refund->id, $b2c['OriginatorConversationID']);
        $this->assertStringEndsWith('/b2c-result', $b2c['ResultURL']);

        $this->providerCallback('b2c-result', ['Result' => [
            'ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
            'OriginatorConversationID' => $refund->id, 'ConversationID' => 'AG_B2C_1', 'TransactionID' => 'QJK3REFND1',
            'ResultParameters' => ['ResultParameter' => [['Key' => 'TransactionAmount', 'Value' => 500], ['Key' => 'ReceiverPartyPublicName', 'Value' => '254712345678 - Jane Doe']]],
        ]])->assertOk();

        $paid = $this->intent($refund->id);
        $this->assertSame('succeeded', $paid->status);
        $this->assertSame('QJK3REFND1', $paid->provider_receipt);
        $this->assertStringNotContainsString('Jane', json_encode($paid->provider_data));
    }

    public function test_a_failed_payout_and_a_method_without_initiator(): void
    {
        $sale = $this->pushed();
        $this->providerCallback('stk', $this->stkCallback($sale->provider_checkout_id, 0))->assertOk();
        $payout = fn () => $this->inTenant(fn () => app(PaymentIntents::class)->payout($this->mpesa->fresh(), $this->intent($sale->id), [
            'id' => (string) Str::uuid7(), 'amount_minor' => 50000, 'currency' => 'KES', 'reference_type' => 'pos.refund', 'reference' => (string) Str::uuid7(),
        ]));

        $refund = $payout();
        $this->providerCallback('b2c-result', ['Result' => ['ResultCode' => 2001, 'ResultDesc' => 'The initiator information is invalid.', 'ConversationID' => 'AG_B2C_1', 'OriginatorConversationID' => $refund->id]])->assertOk();
        $this->assertSame('failed', $this->intent($refund->id)->status);

        $this->inTenant(fn () => $this->mpesa->fill(['settings' => ['shortcode' => '174379'], 'secrets' => [...$this->mpesa->secrets, 'security_credential' => null]])->save());
        $this->assertSame('initiator_missing', $payout()->result_code);
    }
}
