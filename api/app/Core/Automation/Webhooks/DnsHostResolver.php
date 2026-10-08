<?php

namespace App\Core\Automation\Webhooks;

/**
 * HostResolver with a time limit: asks the system's name servers
 * (/etc/resolv.conf) for A and AAAA records over UDP, waiting at most
 * `automation.dns_timeout` seconds per server, and returns no address when
 * none answers in time (the webhook is then refused as unresolved). PHP's
 * own dns_get_record() has no timeout, so a slow resolver could hold a
 * worker; it is used only when no name server is configured.
 */
class DnsHostResolver implements HostResolver
{
    private const A = 1;

    private const AAAA = 28;

    public function __construct(private readonly string $resolvConf = '/etc/resolv.conf') {}

    public function resolve(string $host): array
    {
        $servers = $this->servers();

        if ($servers === []) {
            return $this->fallback($host);
        }

        $timeout = max(1, (int) config('automation.dns_timeout', 2));

        foreach ($servers as $server) {
            $answers = [];
            $answered = false;

            foreach ([self::A, self::AAAA] as $type) {
                $found = $this->query($server, $host, $type, $timeout);

                if ($found !== null) {
                    $answered = true;
                    array_push($answers, ...$found);
                }
            }

            if ($answered) {
                return array_values(array_unique($answers));
            }
        }

        return [];
    }

    /** @return list<string> name servers, at most three */
    private function servers(): array
    {
        $lines = @file($this->resolvConf, FILE_IGNORE_NEW_LINES) ?: [];
        $servers = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*nameserver\s+(\S+)/', $line, $m) === 1 && filter_var($m[1], FILTER_VALIDATE_IP) !== false) {
                $servers[] = $m[1];
            }
        }

        return array_slice($servers, 0, 3);
    }

    /** @return list<string>|null addresses, or null when the server did not answer in time */
    private function query(string $server, string $host, int $type, int $timeout): ?array
    {
        $address = str_contains($server, ':') ? "udp://[{$server}]:53" : "udp://{$server}:53";
        $socket = @stream_socket_client($address, $errno, $error, $timeout);

        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, $timeout);
        $id = random_int(0, 0xFFFF);
        $packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0).$this->name($host).pack('nn', $type, 1);
        fwrite($socket, $packet);
        $response = fread($socket, 4096);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($response === false || $meta['timed_out'] || strlen($response) < 12) {
            return null;
        }

        $header = unpack('nid/nflags/nqd/nan', substr($response, 0, 8));

        if ($header['id'] !== $id) {
            return null;
        }

        return $this->answers($response, $header['qd'], $header['an']);
    }

    private function name(string $host): string
    {
        $out = '';

        foreach (explode('.', rtrim($host, '.')) as $label) {
            $out .= chr(strlen($label)).$label;
        }

        return $out."\0";
    }

    /** @return list<string> */
    private function answers(string $response, int $questions, int $count): array
    {
        $offset = 12;

        for ($i = 0; $i < $questions; $i++) {
            $offset = $this->skipName($response, $offset) + 4;
        }

        $addresses = [];

        for ($i = 0; $i < $count && $offset < strlen($response); $i++) {
            $offset = $this->skipName($response, $offset);

            if ($offset + 10 > strlen($response)) {
                break;
            }

            $record = unpack('ntype/nclass/Nttl/nlength', substr($response, $offset, 10));
            $offset += 10;
            $data = substr($response, $offset, $record['length']);
            $offset += $record['length'];

            if (($record['type'] === self::A && strlen($data) === 4) || ($record['type'] === self::AAAA && strlen($data) === 16)) {
                $addresses[] = (string) inet_ntop($data);
            }
        }

        return $addresses;
    }

    private function skipName(string $response, int $offset): int
    {
        $length = strlen($response);

        while ($offset < $length) {
            $byte = ord($response[$offset]);

            if ($byte === 0) {
                return $offset + 1;
            }

            if (($byte & 0xC0) === 0xC0) {
                return $offset + 2;
            }

            $offset += $byte + 1;
        }

        return $offset;
    }

    /** @return list<string> */
    private function fallback(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }
}
