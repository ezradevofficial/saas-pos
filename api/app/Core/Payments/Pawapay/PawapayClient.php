<?php

namespace App\Core\Payments\Pawapay;

use App\Core\Payments\ProviderUnavailable;
use App\Core\Support\OutboundHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * pawaPay v2 calls with one API token. Every call returns the decoded JSON
 * body, and throws ProviderUnavailable (a fixed message, no body, no
 * token) when nothing usable came back: no connection, a time-out, a 5xx
 * or a 401/403 (the token is wrong).
 */
class PawapayClient
{
    public function __construct(private readonly PawapayCredentials $credentials) {}

    public function deposit(array $body): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post('/v2/deposits', $body), allow: [400, 409]);
    }

    public function depositStatus(string $depositId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/v2/deposits/'.rawurlencode($depositId)));
    }

    public function refund(array $body): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post('/v2/refunds', $body), allow: [400, 409]);
    }

    public function refundStatus(string $refundId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/v2/refunds/'.rawurlencode($refundId)));
    }

    /** @return array<string, mixed>|null the prediction ({country, provider, phoneNumber}); null when the number is not a mobile money number */
    public function predictProvider(string $phone): ?array
    {
        $answer = $this->send(fn (PendingRequest $http) => $http->post('/v2/predict-provider', ['phoneNumber' => $phone]), allow: [400]);

        return isset($answer['provider'], $answer['phoneNumber']) ? $answer : null;
    }

    /** @return list<array{id: string, key: string}> */
    public function publicKeys(): array
    {
        $keys = $this->send(fn (PendingRequest $http) => $http->get('/v2/public-key/http'));

        return array_values(array_filter($keys, fn ($key) => is_array($key) && is_string($key['id'] ?? null) && is_string($key['key'] ?? null)));
    }

    /** @param  list<int>  $allow  HTTP statuses whose body is an answer, not a failure */
    private function send(callable $call, array $allow = []): array
    {
        if (! $this->credentials->isSet()) {
            throw new ProviderUnavailable(__('payments.errors.provider_not_configured'));
        }

        try {
            /** @var Response $response */
            $response = $call(OutboundHttp::client($this->credentials->baseUrl, (int) config('payments.pawapay.timeout', 20))->withToken($this->credentials->token));
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable(previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProviderUnavailable(__('payments.errors.provider_auth'));
        }

        if (! $response->successful() && ! in_array($response->status(), $allow, true)) {
            throw new ProviderUnavailable;
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
