<?php

namespace App\Core\Webhooks\Delivery;

use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Automation\Webhooks\WebhookRefused;
use App\Core\Automation\Webhooks\WebhookSender;
use App\Core\Automation\Webhooks\WebhookTarget;

/**
 * HOOK-01: where a subscription may send, with the SSRF rules of
 * WebhookSender (the automation webhook's, also behind OutboundHttp):
 * HTTPS only, no credentials, no private, loopback, link-local or metadata
 * address, checked on save (problem(), no DNS) and again at every send
 * (target(): resolved once, every address public, the connection pinned to
 * the checked one, so DNS rebinding cannot redirect it; no redirects).
 *
 * Local development only (APP_ENV=local): hosts listed in
 * `webhooks.local_hosts` may be called over plain HTTP on any address, so a
 * receiver script on the developer's machine can be used. Ignored in every
 * other environment, the test suite included.
 */
class WebhookEndpoints
{
    public function __construct(
        private readonly WebhookSender $sender,
        private readonly HostResolver $resolver,
    ) {}

    /** Why $url may never be called (a reason key), or null. */
    public function problem(mixed $url): ?string
    {
        if (is_string($url) && $this->isLocalDevelopment($url)) {
            return null;
        }

        return WebhookSender::urlProblem($url);
    }

    /** The one address to call $url at; throws WebhookRefused. */
    public function target(string $url): WebhookTarget
    {
        if (! $this->isLocalDevelopment($url)) {
            return $this->sender->target($url);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) $parts['scheme']);
        $host = WebhookSender::hostOf($url);
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : ($this->resolver->resolve($host)[0] ?? null);

        if ($ip === null) {
            throw new WebhookRefused('unresolved');
        }

        return new WebhookTarget($url, $host, $port, $ip, plainHttp: $scheme === 'http');
    }

    /**
     * The URL as shown in the app, notifications, the audit log and the
     * export: its scheme, host and port, with any path or query masked
     * (`https://hooks.slack.com/…/••••`), as either may carry a token.
     */
    public static function display(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return '••••';
        }

        $shown = strtolower((string) ($parts['scheme'] ?? 'https')).'://'.strtolower((string) $parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = (string) ($parts['path'] ?? '');

        return ($path !== '' && $path !== '/') || isset($parts['query']) ? $shown.'/…/••••' : $shown;
    }

    private function isLocalDevelopment(string $url): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return in_array(WebhookSender::hostOf($url), array_map('strtolower', (array) config('webhooks.local_hosts', [])), true);
    }
}
