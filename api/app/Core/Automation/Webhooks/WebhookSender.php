<?php

namespace App\Core\Automation\Webhooks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * AUTO-03 "call webhook", with SSRF protection:
 *
 * - HTTPS only, no credentials in the URL, a real host name with a dot
 *   (or a public IP address); checked when the rule is saved (urlProblem)
 *   and again before every call;
 * - the host name is resolved once and every address it resolves to must
 *   be public (AddressGuard); the request then connects to that checked
 *   address (CURLOPT_RESOLVE pins it), so a DNS answer that changes
 *   between the check and the connection (rebinding) is never used;
 * - no redirects are followed, no proxy from the environment is used, the
 *   whole call times out after `automation.webhook_timeout` seconds;
 * - only the status and the first `automation.webhook_response_bytes`
 *   bytes of the answer are read.
 */
class WebhookSender
{
    public const MAX_URL = 2000;

    private const HOST = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/';

    public function __construct(private readonly HostResolver $resolver) {}

    /** Why the URL may never be called (a reason key), or null; no DNS lookup. */
    public static function urlProblem(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > self::MAX_URL || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1) {
            return 'invalid';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'invalid';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'not_https';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials';
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return 'invalid';
        }

        $host = self::host($parts['host']);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return AddressGuard::isPublic($host) ? null : 'private_address';
        }

        // Numeric or hexadecimal shorthand ("2130706433", "0x7f.1") resolves to an IP in some libraries.
        if (preg_match('/^[0-9.]+$/', $host) === 1 || preg_match('/(^|\.)0x/', $host) === 1 || preg_match(self::HOST, $host) !== 1) {
            return 'invalid';
        }

        return null;
    }

    /** The URL with the one public address to connect to; throws WebhookRefused. */
    public function target(string $url): WebhookTarget
    {
        if (($problem = self::urlProblem($url)) !== null) {
            throw new WebhookRefused($problem);
        }

        $parts = parse_url($url);
        $host = self::host((string) $parts['host']);
        $port = (int) ($parts['port'] ?? 443);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return new WebhookTarget($url, $host, $port, $host);
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new WebhookRefused('unresolved');
        }

        // Every answer must be public: a name mixing public and private addresses is refused.
        foreach ($addresses as $address) {
            if (! AddressGuard::isPublic($address)) {
                throw new WebhookRefused('private_address');
            }
        }

        return new WebhookTarget($url, $host, $port, $addresses[0]);
    }

    /** The HTTP client options for a call to $target (pinned address, no redirects, timeouts). */
    public function options(WebhookTarget $target): array
    {
        $timeout = (int) config('automation.webhook_timeout', 5);

        return [
            'allow_redirects' => false,
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'proxy' => '',
            'stream' => true,
            'curl' => [
                CURLOPT_RESOLVE => [$target->resolveEntry()],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
            ],
        ];
    }

    /**
     * POST the signed JSON body. Returns the status and the start of the
     * answer; throws ConnectionException when the host cannot be reached.
     *
     * @return array{status: int, body: string}
     *
     * @throws ConnectionException
     */
    public function send(WebhookTarget $target, string $body, string $secret, string $id, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        $response = Http::withOptions($this->options($target))
            ->withHeaders([
                'User-Agent' => 'automation-webhook/1',
                Signature::ID_HEADER => $id,
                Signature::TIMESTAMP_HEADER => (string) $timestamp,
                Signature::SIGNATURE_HEADER => Signature::sign($secret, $timestamp, $body),
            ])
            ->withBody($body, 'application/json')
            ->post($target->url);

        $stream = $response->toPsrResponse()->getBody();
        $limit = (int) config('automation.webhook_response_bytes', 1024);
        $start = '';

        while (strlen($start) < $limit && ! $stream->eof()) {
            $chunk = $stream->read($limit - strlen($start));

            if ($chunk === '') {
                break;
            }

            $start .= $chunk;
        }

        $stream->close();

        return ['status' => $response->status(), 'body' => mb_scrub($start, 'UTF-8')];
    }

    private static function host(string $host): string
    {
        return strtolower(trim($host, '[]'));
    }
}
