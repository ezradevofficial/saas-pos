<?php

namespace Tests\Unit\Core\Automation;

use App\Core\Automation\Webhooks\DnsHostResolver;
use App\Core\Automation\Webhooks\WebhookSender;
use App\Core\Automation\Webhooks\WebhookTarget;
use App\Core\Automation\Webhooks\WebhookUnreachable;
use Tests\Support\Automation\FakeHostResolver;
use Tests\TestCase;

/**
 * AUTO-03 webhooks over a real curl handler (no Http::fake): the request
 * goes to the pinned address, never to whatever the host name resolves to
 * (the host here does not resolve at all), and a closed port is reported
 * as unreachable. DNS lookups give up after `automation.dns_timeout`.
 * Local addresses are used by building the target directly (the guard
 * would refuse them).
 */
class WebhookSenderNetworkTest extends TestCase
{
    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    public function test_a_closed_pinned_port_is_unreachable(): void
    {
        config(['automation.webhook_timeout' => 2]);
        $port = $this->freePort();
        $target = new WebhookTarget("https://pin-test.invalid:{$port}/hook", 'pin-test.invalid', $port, '127.0.0.1');

        $this->expectException(WebhookUnreachable::class);
        (new WebhookSender(new FakeHostResolver))->send($target, '{}', 'secret-secret-secret', 'run:0');
    }

    public function test_the_connection_goes_to_the_pinned_address(): void
    {
        config(['automation.webhook_timeout' => 1]);
        // A listener that accepts the connection but never completes TLS.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $target = new WebhookTarget("https://pin-test.invalid:{$port}/hook", 'pin-test.invalid', $port, '127.0.0.1');

        try {
            (new WebhookSender(new FakeHostResolver))->send($target, '{}', 'secret-secret-secret', 'run:0');
            $this->fail('the TLS handshake cannot succeed');
        } catch (WebhookUnreachable) {
        }

        // "pin-test.invalid" resolves nowhere: the only way in was the pin.
        $connection = @stream_socket_accept($server, 1);
        $this->assertNotFalse($connection, 'curl connected to the pinned 127.0.0.1');
        fclose($server);
    }

    public function test_dns_lookups_give_up_after_the_timeout(): void
    {
        config(['automation.dns_timeout' => 1]);
        $conf = tempnam(sys_get_temp_dir(), 'resolv');
        // A documentation address: nothing answers there.
        file_put_contents($conf, "nameserver 192.0.2.1\n");

        $started = microtime(true);
        $addresses = (new DnsHostResolver($conf))->resolve('hooks.example.com');
        $elapsed = microtime(true) - $started;
        unlink($conf);

        $this->assertSame([], $addresses);
        $this->assertLessThan(4.5, $elapsed, 'two lookups (A, AAAA) of at most a second each');
    }
}
