<?php

namespace App\Core\Support;

use App\Core\Automation\Webhooks\WebhookSender;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Calls to the payment providers and tax authorities (Daraja, KRA eTIMS),
 * held to the webhook rules (AUTO-03, WebhookSender): HTTPS only, no
 * credentials in the URL, never a private, loopback or link-local address
 * literal, no redirects, no proxy from the environment, and a time limit.
 * Their base URLs come from the platform's configuration (environment),
 * never from a tenant, so the host is not pinned to one resolved address
 * the way a tenant's webhook is.
 */
final class OutboundHttp
{
    public static function client(string $baseUrl, int $timeout): PendingRequest
    {
        $problem = WebhookSender::urlProblem($baseUrl);

        if ($problem !== null) {
            throw new InvalidArgumentException("Refused outbound base URL ({$problem}).");
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->withOptions([
                'allow_redirects' => false,
                'protocols' => ['https'],
                'proxy' => '',
            ])
            ->withUserAgent('platform-integrations/1')
            ->acceptJson()
            ->asJson();
    }
}
