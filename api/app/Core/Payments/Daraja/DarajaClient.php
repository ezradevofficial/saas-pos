<?php

namespace App\Core\Payments\Daraja;

use App\Core\Payments\ProviderUnavailable;
use App\Core\Support\OutboundHttp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Safaricom Daraja API calls for one company's app (DarajaCredentials).
 * Base URL and paths come from `payments.mpesa` (sandbox by default).
 *
 * - OAuth: client credentials (consumer key and secret, HTTP Basic); the
 *   token is cached per tenant, method and key until `token_margin`
 *   seconds before it expires, and fetched again once if a call answers
 *   401.
 * - Lipa na M-Pesa Online (STK push) and its query: the password is
 *   base64(shortcode + passkey + timestamp), the timestamp YmdHis in
 *   Nairobi time.
 * - C2B URL registration, B2C payment request, transaction status query
 *   and reversal: the last three take the initiator name and its
 *   security credential (the initiator password encrypted with
 *   Safaricom's certificate, made on the Daraja portal).
 *
 * Every call returns the decoded JSON body (Daraja's errors included:
 * `errorCode`, `errorMessage`) and throws ProviderUnavailable when
 * nothing usable came back (no connection, time-out, 5xx without a
 * Daraja error body).
 */
class DarajaClient
{
    public function __construct(private readonly DarajaCredentials $credentials) {}

    public function token(bool $fresh = false): string
    {
        $key = $this->tokenCacheKey();

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http()
                ->withBasicAuth($this->credentials->consumerKey, $this->credentials->consumerSecret)
                ->get($this->path('oauth'));
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable(previous: $e);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new ProviderUnavailable(__('payments.errors.provider_auth'));
        }

        $ttl = max(1, (int) $response->json('expires_in', 3599) - (int) config('payments.mpesa.token_margin', 60));
        Cache::put($key, $token, $ttl);

        return $token;
    }

    /** @return array{0: string, 1: string} [password, timestamp] for STK calls */
    public function stkPassword(?CarbonImmutable $at = null): array
    {
        $timestamp = ($at ?? CarbonImmutable::now())->setTimezone('Africa/Nairobi')->format('YmdHis');

        return [base64_encode($this->credentials->shortcode.$this->credentials->passkey.$timestamp), $timestamp];
    }

    /** STK push: ask $phone (2547XXXXXXXX) to pay $amount whole shillings. */
    public function stkPush(string $phone, int $amount, string $accountReference, string $description, string $callbackUrl): array
    {
        [$password, $timestamp] = $this->stkPassword();

        return $this->post('stk_push', [
            'BusinessShortCode' => $this->credentials->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => $this->credentials->transactionType === 'till' ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $this->credentials->payTo(),
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => mb_substr($accountReference, 0, 12),
            'TransactionDesc' => mb_substr($description, 0, 13),
        ]);
    }

    public function stkQuery(string $checkoutRequestId): array
    {
        [$password, $timestamp] = $this->stkPassword();

        return $this->post('stk_query', [
            'BusinessShortCode' => $this->credentials->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);
    }

    /** C2B: where Safaricom sends validation requests and confirmations for the payTo shortcode. */
    public function registerC2bUrls(string $validationUrl, string $confirmationUrl, string $responseType = 'Completed'): array
    {
        return $this->post('c2b_register', [
            'ShortCode' => $this->credentials->payTo(),
            'ResponseType' => $responseType,
            'ConfirmationURL' => $confirmationUrl,
            'ValidationURL' => $validationUrl,
        ]);
    }

    /** B2C: pay $amount whole shillings to $phone (a refund). */
    public function b2c(string $originatorConversationId, string $phone, int $amount, string $remarks, string $resultUrl, string $timeoutUrl, ?string $occasion = null): array
    {
        return $this->post('b2c', [
            'OriginatorConversationID' => $originatorConversationId,
            'InitiatorName' => (string) $this->credentials->initiatorName,
            'SecurityCredential' => (string) $this->credentials->securityCredential,
            'CommandID' => (string) config('payments.mpesa.b2c_command', 'BusinessPayment'),
            'Amount' => $amount,
            'PartyA' => $this->credentials->b2cShortcode ?? $this->credentials->shortcode,
            'PartyB' => $phone,
            'Remarks' => mb_substr($remarks, 0, 100),
            'QueueTimeOutURL' => $timeoutUrl,
            'ResultURL' => $resultUrl,
            'Occasion' => mb_substr((string) $occasion, 0, 100),
        ]);
    }

    /** Transaction status: ask where an M-Pesa transaction (receipt) stands; the answer comes to $resultUrl. */
    public function transactionStatus(string $transactionId, string $resultUrl, string $timeoutUrl, string $remarks = 'Check'): array
    {
        return $this->post('transaction_status', [
            'Initiator' => (string) $this->credentials->initiatorName,
            'SecurityCredential' => (string) $this->credentials->securityCredential,
            'CommandID' => 'TransactionStatusQuery',
            'TransactionID' => $transactionId,
            'PartyA' => $this->credentials->payTo(),
            // 4: organisation shortcode (Paybill); 2: Till number.
            'IdentifierType' => $this->credentials->transactionType === 'till' ? '2' : '4',
            'ResultURL' => $resultUrl,
            'QueueTimeOutURL' => $timeoutUrl,
            'Remarks' => $remarks,
            'Occasion' => '',
        ]);
    }

    /** Reversal of a C2B transaction (where Safaricom allows it for the shortcode). */
    public function reversal(string $transactionId, int $amount, string $resultUrl, string $timeoutUrl, string $remarks = 'Reversal'): array
    {
        return $this->post('reversal', [
            'Initiator' => (string) $this->credentials->initiatorName,
            'SecurityCredential' => (string) $this->credentials->securityCredential,
            'CommandID' => 'TransactionReversal',
            'TransactionID' => $transactionId,
            'Amount' => $amount,
            'ReceiverParty' => $this->credentials->payTo(),
            'RecieverIdentifierType' => '11',
            'ResultURL' => $resultUrl,
            'QueueTimeOutURL' => $timeoutUrl,
            'Remarks' => $remarks,
            'Occasion' => '',
        ]);
    }

    /** @param array<string, scalar> $body */
    private function post(string $path, array $body): array
    {
        $response = $this->send($path, $body, $this->token());

        // An expired or revoked token: fetch a new one, once.
        if ($response->status() === 401) {
            $response = $this->send($path, $body, $this->token(fresh: true));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new ProviderUnavailable;
        }

        // A server error without Daraja's error body tells nothing.
        if ($response->serverError() && ! isset($json['errorCode'])) {
            throw new ProviderUnavailable;
        }

        return $json;
    }

    private function send(string $path, array $body, string $token): Response
    {
        try {
            return $this->http()->withToken($token)->post($this->path($path), $body);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable(previous: $e);
        }
    }

    private function http()
    {
        return OutboundHttp::client((string) config('payments.mpesa.base_url'), (int) config('payments.mpesa.timeout', 15));
    }

    private function path(string $name): string
    {
        return (string) config("payments.mpesa.paths.{$name}");
    }

    private function tokenCacheKey(): string
    {
        return 'payments:daraja:token:'.$this->credentials->tenantId.':'.$this->credentials->methodId.':'
            .hash('sha256', config('payments.mpesa.base_url').'|'.$this->credentials->consumerKey);
    }
}
