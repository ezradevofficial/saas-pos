<?php

namespace Tests\Concerns;

use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\CallbackTokens;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The organisation (BuildsOrganisation) with its company's currencies and
 * payment methods, the M-Pesa method configured for Daraja (a Paybill)
 * and switched on, and a paired till at Outlet A. Daraja answers through
 * Http::fake (fakeDaraja); callbacks come from a Safaricom address.
 */
trait BuildsPayments
{
    use BuildsOrganisation;

    protected const SAFARICOM_IP = '196.201.214.200';

    protected const SECRET = 'zq-known-secret-7f3a9c';

    protected PaymentMethod $mpesa;

    protected Device $till;

    protected string $tillToken;

    protected function setUpPayments(array $settings = [], array $secrets = []): void
    {
        $this->setUpOrganisation();

        config([
            'payments.callback_base_url' => 'https://api.example.com',
            'payments.mpesa.base_url' => 'https://sandbox.safaricom.co.ke',
            'payments.mpesa.enforce_callback_ips' => true,
            'payments.mpesa.callback_ips' => [self::SAFARICOM_IP],
            'payments.drivers' => [],
        ]);

        $this->inTenant(function () use ($settings, $secrets) {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => app(DefaultPaymentMethods::class)->seed($this->acme));

            $this->mpesa = PaymentMethod::query()->where('company_id', $this->acme->id)->where('provider', 'mpesa_ke')->sole();
            $this->mpesa->fill([
                'settings' => ['shortcode' => '174379', ...$settings],
                'secrets' => ['consumer_key' => 'ck-'.self::SECRET, 'consumer_secret' => 'cs-'.self::SECRET, 'passkey' => 'pk-'.self::SECRET, ...$secrets],
                'active' => true,
            ])->save();

            [$this->till, $this->tillToken] = $this->pairedTill($this->locationA, 'Till A');
        });
    }

    /** @return array{0: Device, 1: string} a paired device at $location and its token */
    protected function pairedTill(Location $location, string $name): array
    {
        $device = Device::create(['location_id' => $location->id, 'name' => $name]);
        $device->forceFill(['status' => Device::STATUS_ACTIVE, 'paired_at' => now()])->save();

        return [$device, $device->issueToken($name, null, null)->plainTextToken];
    }

    /** @return array<string, string> */
    protected function tillHeaders(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->tillToken), 'Accept' => 'application/json'];
    }

    /**
     * Daraja answers: OAuth, STK push and query, B2C, transaction status and
     * C2B registration, each overridable by path fragment.
     *
     * @param  array<string, mixed>  $overrides  path fragment => response
     */
    protected function fakeDaraja(array $overrides = []): void
    {
        Http::fake(array_merge([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok-daraja-1', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => fn () => Http::response([
                'MerchantRequestID' => '29115-34620561-1',
                'CheckoutRequestID' => 'ws_CO_'.Str::random(16),
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
            'sandbox.safaricom.co.ke/mpesa/stkpushquery/*' => Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500),
            'sandbox.safaricom.co.ke/mpesa/b2c/*' => Http::response(['ConversationID' => 'AG_B2C_1', 'OriginatorConversationID' => 'orig-1', 'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.']),
            'sandbox.safaricom.co.ke/mpesa/transactionstatus/*' => Http::response(['ConversationID' => 'AG_TS_1', 'OriginatorConversationID' => 'orig-ts-1', 'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.']),
            'sandbox.safaricom.co.ke/mpesa/c2b/*' => Http::response(['OriginatorCoversationID' => '', 'ResponseCode' => '0', 'ResponseDescription' => 'Success']),
        ], $overrides));
    }

    protected function callbackToken(?PaymentMethod $method = null): string
    {
        return $this->inTenant(fn () => app(CallbackTokens::class)->token(($method ?? $this->mpesa)->fresh()));
    }

    protected function providerCallback(string $kind, array $payload, ?string $token = null, string $ip = self::SAFARICOM_IP): TestResponse
    {
        $token ??= $this->callbackToken();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson("/api/v1/payments/callbacks/{$token}/{$kind}", $payload);
    }

    /** An STK push for a sale at the till: amount in minor units (whole shillings). */
    protected function push(string $amountMinor = '150000', string $phone = '0712345678', array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/payments/intents', [
            'payment_method_id' => $this->mpesa->id,
            'mode' => 'stk',
            'amount_minor' => $amountMinor,
            'currency' => 'KES',
            'phone' => $phone,
            'reference_type' => 'pos.sale',
            'reference' => '019a0000-0000-7000-8000-000000000001',
            ...$extra,
        ], $this->tillHeaders());
    }

    /** Daraja's STK result callback body. */
    protected function stkCallback(string $checkoutId, int $code, ?string $receipt = 'QJK3ABC123', string $amount = '1500.00'): array
    {
        $callback = [
            'MerchantRequestID' => '29115-34620561-1',
            'CheckoutRequestID' => $checkoutId,
            'ResultCode' => $code,
            'ResultDesc' => $code === 0 ? 'The service request is processed successfully.' : 'Request cancelled by user',
        ];

        if ($code === 0) {
            $callback['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => (float) $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                ['Name' => 'TransactionDate', 'Value' => 20261009101500],
                ['Name' => 'PhoneNumber', 'Value' => 254712345678],
            ]];
        }

        return ['Body' => ['stkCallback' => $callback]];
    }

    /** Daraja's C2B confirmation body. */
    protected function c2bConfirmation(string $transId, string $amount, string $billRef = ''): array
    {
        return [
            'TransactionType' => 'Pay Bill',
            'TransID' => $transId,
            'TransTime' => '20261009101500',
            'TransAmount' => $amount,
            'BusinessShortCode' => '174379',
            'BillRefNumber' => $billRef,
            'InvoiceNumber' => '',
            'OrgAccountBalance' => '49197.00',
            'ThirdPartyTransID' => '',
            'MSISDN' => '2547 ***** 126',
            'FirstName' => 'Jane',
            'MiddleName' => '',
            'LastName' => 'Doe',
        ];
    }
}
