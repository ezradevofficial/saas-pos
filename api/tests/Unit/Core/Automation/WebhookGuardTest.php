<?php

namespace Tests\Unit\Core\Automation;

use App\Core\Automation\Webhooks\AddressGuard;
use App\Core\Automation\Webhooks\Signature;
use App\Core\Automation\Webhooks\WebhookRefused;
use App\Core\Automation\Webhooks\WebhookSender;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Automation\FakeHostResolver;
use Tests\TestCase;

/**
 * AUTO-03 webhooks, SSRF protection and signatures: only public addresses
 * (IPv4 and IPv6, mapped addresses unwrapped), HTTPS only, no credentials
 * or numeric host tricks; a host name is resolved once, every answer must
 * be public, and the connection is pinned to the checked address (DNS
 * rebinding cannot swap it). HMAC-SHA256 over "timestamp.body".
 */
class WebhookGuardTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function blocked(): array
    {
        return array_map(fn ($ip) => [$ip], [
            'this network' => '0.0.0.0',
            'private 10/8' => '10.1.2.3',
            'carrier-grade NAT' => '100.64.0.1',
            'loopback' => '127.0.0.1',
            'loopback, other' => '127.255.255.254',
            'link-local' => '169.254.10.10',
            'cloud metadata' => '169.254.169.254',
            'private 172.16/12' => '172.20.0.1',
            'IETF protocol' => '192.0.0.8',
            'documentation' => '192.0.2.10',
            'private 192.168/16' => '192.168.1.1',
            'benchmarking' => '198.18.0.1',
            'multicast' => '224.0.0.1',
            'reserved' => '240.0.0.1',
            'AS112 v4' => '192.31.196.1',
            'AMT' => '192.52.193.1',
            'AS112 direct' => '192.175.48.1',
            'broadcast' => '255.255.255.255',
            'IPv6 unspecified' => '::',
            'IPv6 loopback' => '::1',
            'IPv6 unique local' => 'fd00::1',
            'IPv6 metadata (AWS)' => 'fd00:ec2::254',
            'IPv6 link-local' => 'fe80::1',
            'IPv6 multicast' => 'ff02::1',
            'IPv4-mapped loopback' => '::ffff:127.0.0.1',
            'IPv4-mapped metadata' => '::ffff:169.254.169.254',
            'IPv4-compatible loopback' => '::127.0.0.1',
            'NAT64 of private' => '64:ff9b::a00:1',
            '6to4' => '2002:7f00:1::1',
            'Teredo' => '2001:0:4136:e378::1',
            'IPv6 documentation' => '2001:db8::1',
            'not an address' => 'example.com',
        ]);
    }

    #[DataProvider('blocked')]
    public function test_non_public_addresses_are_refused(string $ip): void
    {
        $this->assertFalse(AddressGuard::isPublic($ip));
    }

    /** @return array<string, array{string}> */
    public static function public(): array
    {
        return array_map(fn ($ip) => [$ip], [
            'IPv4' => '93.184.216.34',
            'IPv4 next to 172.16/12' => '172.32.0.1',
            'IPv4 next to 100.64/10' => '100.128.0.1',
            'IPv6' => '2606:2800:220:1:248:1893:25c8:1946',
            'IPv4-mapped public' => '::ffff:93.184.216.34',
        ]);
    }

    #[DataProvider('public')]
    public function test_public_addresses_are_allowed(string $ip): void
    {
        $this->assertTrue(AddressGuard::isPublic($ip));
    }

    /** @return array<string, array{string, ?string}> */
    public static function urls(): array
    {
        return [
            'https with a host' => ['https://hooks.example.com/in?x=1', null],
            'https with a port' => ['https://hooks.example.com:8443/in', null],
            'public IP literal' => ['https://93.184.216.34/in', null],
            'http' => ['http://hooks.example.com/in', 'not_https'],
            'ftp' => ['ftp://hooks.example.com/in', 'not_https'],
            'credentials' => ['https://user:pass@hooks.example.com/in', 'credentials'],
            'private IP literal' => ['https://10.0.0.1/in', 'private_address'],
            'loopback IPv6 literal' => ['https://[::1]/in', 'private_address'],
            'metadata literal' => ['https://169.254.169.254/latest/meta-data', 'private_address'],
            'decimal host' => ['https://2130706433/in', 'invalid'],
            'hex host' => ['https://0x7f.0.0.1/in', 'invalid'],
            'short dotted host' => ['https://127.1/in', 'invalid'],
            'single-label host' => ['https://localhost/in', 'invalid'],
            'spaces' => ['https://hooks.example.com/a b', 'invalid'],
            'no host' => ['https:///in', 'invalid'],
            'not a string' => ['', 'invalid'],
        ];
    }

    #[DataProvider('urls')]
    public function test_urls_are_checked_before_any_lookup(string $url, ?string $problem): void
    {
        $this->assertSame($problem, WebhookSender::urlProblem($url));
    }

    public function test_a_host_resolving_to_any_private_address_is_refused(): void
    {
        $sender = new WebhookSender(new FakeHostResolver([
            'mixed.example.com' => [['93.184.216.34', '10.0.0.5']],
            'inside.example.com' => [['::1']],
            'nowhere.example.com' => [[]],
        ]));

        foreach (['mixed.example.com' => 'private_address', 'inside.example.com' => 'private_address', 'nowhere.example.com' => 'unresolved'] as $host => $reason) {
            try {
                $sender->target("https://{$host}/hook");
                $this->fail("{$host} was allowed");
            } catch (WebhookRefused $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    public function test_the_host_is_resolved_once_and_the_connection_pinned_to_that_address(): void
    {
        // DNS rebinding: the first answer is public, later ones point inside.
        $dns = new FakeHostResolver(['rebind.example.com' => [['93.184.216.34'], ['127.0.0.1']]]);
        $sender = new WebhookSender($dns);

        $target = $sender->target('https://rebind.example.com:8443/hook');
        $options = $sender->options($target);

        $this->assertSame(1, $dns->lookups['rebind.example.com']);
        $this->assertSame('93.184.216.34', $target->ip);
        $this->assertSame(['curl' => [CURLOPT_RESOLVE => ['rebind.example.com:8443:93.184.216.34']]], array_intersect_key($options, ['curl' => 1]));
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['https'], $options['protocols']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(5, $options['timeout']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertArrayNotHasKey('stream', $options, 'the stream handler would ignore the pin');
    }

    public function test_an_ip_literal_needs_no_pin(): void
    {
        $sender = new WebhookSender(new FakeHostResolver);

        $this->assertArrayNotHasKey('curl', $sender->options($sender->target('https://93.184.216.34/in')));
    }

    public function test_an_ipv6_answer_is_pinned_in_brackets(): void
    {
        $sender = new WebhookSender(new FakeHostResolver(['v6.example.com' => [['2606:2800:220:1:248:1893:25c8:1946']]]));

        $this->assertSame(['v6.example.com:443:[2606:2800:220:1:248:1893:25c8:1946]'], $sender->options($sender->target('https://v6.example.com/x'))['curl'][CURLOPT_RESOLVE]);
    }

    public function test_the_signature_is_hmac_sha256_over_timestamp_and_body(): void
    {
        $body = '{"event":"automation.rule_run"}';
        $signature = Signature::sign('secret-key-123456', 1791446400, $body);

        $this->assertSame('sha256='.hash_hmac('sha256', '1791446400.'.$body, 'secret-key-123456'), $signature);
        $this->assertTrue(Signature::verify('secret-key-123456', 1791446400, $body, $signature));
        $this->assertFalse(Signature::verify('another-secret-123', 1791446400, $body, $signature));
        $this->assertFalse(Signature::verify('secret-key-123456', 1791446401, $body, $signature));
        $this->assertFalse(Signature::verify('secret-key-123456', 1791446400, $body.' ', $signature));
    }
}
