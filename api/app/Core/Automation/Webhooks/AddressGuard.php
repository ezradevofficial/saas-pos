<?php

namespace App\Core\Automation\Webhooks;

/**
 * AUTO-03 webhooks, SSRF protection: only public internet addresses may be
 * called. Refused: private, loopback, link-local (169.254.0.0/16, which
 * holds cloud metadata at 169.254.169.254), carrier-grade NAT, multicast,
 * reserved and documentation ranges; for IPv6 everything outside global
 * unicast (2000::/3: so ::1, fc00::/7, fe80::/10, ff00::/8, NAT64
 * 64:ff9b::/96), the 6to4 and Teredo relays and documentation prefixes,
 * and IPv4-mapped addresses are checked as the IPv4 address they carry.
 */
final class AddressGuard
{
    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const V6_GLOBAL = '2000::/3';

    private const V6_BLOCKED = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];

    public static function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16) {
            // ::ffff:a.b.c.d is the IPv4 address it carries.
            if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
                return self::isPublic((string) inet_ntop(substr($binary, 12)));
            }

            if (! self::inRange($binary, self::V6_GLOBAL)) {
                return false;
            }

            foreach (self::V6_BLOCKED as $range) {
                if (self::inRange($binary, $range)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::V4_BLOCKED as $range) {
            if (self::inRange($binary, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $binary, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $net = inet_pton($network);

        if ($net === false || strlen($net) !== strlen($binary)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($binary, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
