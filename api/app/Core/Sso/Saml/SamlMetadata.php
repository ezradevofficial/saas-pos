<?php

namespace App\Core\Sso\Saml;

use App\Core\Http\ApiException;
use Illuminate\Support\Facades\Http;
use OneLogin\Saml2\IdPMetadataParser;
use Throwable;

/**
 * AUTH-11, ADR 023: an IdP's SAML metadata, pasted or fetched by URL, read
 * into the connection's settings (entity id, sign-in URL, signing
 * certificates). Shown and saved like typed values.
 *
 * Fetching is SSRF-guarded: https only, the host must resolve only to
 * public addresses (never private, reserved, carrier-grade NAT, NAT64 or
 * 6to4) and the request is pinned to them (no rebinding), no redirects, a
 * timeout, and the body read with a running byte cap. The URL is kept, and
 * `sso:refresh-metadata` reads it again daily (SamlMetadataRefresh).
 */
class SamlMetadata
{
    public const MAX_CERTIFICATES = 2;

    public function __construct(private readonly HostAddresses $addresses) {}

    /**
     * @return array{idp_entity_id: string, idp_sso_url: string, idp_certificates: list<string>}
     *
     * @throws ApiException 422 metadata_invalid
     */
    public function fromXml(string $xml): array
    {
        try {
            $idp = IdPMetadataParser::parseXML($xml)['idp'] ?? null;
        } catch (Throwable) {
            $idp = null;
        }

        $certificates = array_values(array_filter(array_map(
            fn ($cert) => self::normaliseCertificate((string) $cert),
            (array) ($idp['x509certMulti']['signing'] ?? (isset($idp['x509cert']) ? [$idp['x509cert']] : [])),
        )));

        $entityId = $idp['entityId'] ?? null;
        $url = $idp['singleSignOnService']['url'] ?? null;

        if (! is_string($entityId) || $entityId === '' || ! is_string($url) || ! str_starts_with($url, 'https://') || $certificates === []) {
            throw self::invalid('metadata_xml');
        }

        return [
            'idp_entity_id' => $entityId,
            'idp_sso_url' => $url,
            'idp_certificates' => array_slice($certificates, 0, self::MAX_CERTIFICATES),
        ];
    }

    /**
     * @return array{idp_entity_id: string, idp_sso_url: string, idp_certificates: list<string>, metadata_url: string}
     *
     * @throws ApiException 422 metadata_unreachable, metadata_invalid
     */
    public function fromUrl(string $url): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (($parts['scheme'] ?? null) !== 'https' || ! is_string($host) || isset($parts['user']) || isset($parts['pass'])) {
            throw self::unreachable();
        }

        $port = (int) ($parts['port'] ?? 443);
        $ips = $this->addresses->resolve($host);

        if ($ips === [] || array_filter($ips, fn (string $ip) => ! self::isPublic($ip)) !== []) {
            throw self::unreachable();
        }

        $max = (int) config('sso.metadata_max_bytes');

        try {
            $response = Http::timeout((int) config('sso.metadata_timeout'))
                ->withOptions([
                    'stream' => true,
                    'allow_redirects' => false,
                    // Pinned to the addresses just checked.
                    'curl' => [CURLOPT_RESOLVE => array_map(fn ($ip) => "{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip), $ips)],
                    'on_headers' => function ($response) use ($max) {
                        if ((int) $response->getHeaderLine('Content-Length') > $max) {
                            throw new \RuntimeException('Metadata too large.');
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable) {
            throw self::unreachable();
        }

        if (! $response->successful()) {
            throw self::unreachable();
        }

        // Read with a running cap: a body without (or lying about) its length stops here.
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        try {
            while (! $stream->eof() && strlen($body) <= $max) {
                $body .= $stream->read(8192);
            }
        } catch (Throwable) {
            throw self::unreachable();
        } finally {
            $stream->close();
        }

        if (strlen($body) > $max) {
            throw self::unreachable();
        }

        try {
            return [...$this->fromXml($body), 'metadata_url' => $url];
        } catch (ApiException) {
            throw self::invalid('metadata_url');
        }
    }

    /** A certificate as PEM, or null when it does not parse. */
    public static function normaliseCertificate(string $certificate): ?string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certificate) ?? '';

        if ($body === '' || base64_decode($body, true) === false) {
            return null;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";

        return @openssl_x509_read($pem) === false ? null : $pem;
    }

    /**
     * Ranges PHP's filters let through that still reach internal networks:
     * carrier-grade NAT, NAT64 (an IPv4 address inside) and 6to4.
     */
    public const DENIED_RANGES = ['100.64.0.0/10', '64:ff9b::/96', '2002::/16'];

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::DENIED_RANGES as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $address = inet_pton($ip);
        $network = inet_pton($subnet);

        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        return $rest === 0 || ((ord($address[$bytes]) ^ ord($network[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }

    private static function unreachable(): ApiException
    {
        $message = __('sso.errors.metadata_unreachable');

        return new ApiException(422, 'metadata_unreachable', $message, ['metadata_url' => [$message]]);
    }

    private static function invalid(string $field): ApiException
    {
        $message = __('sso.errors.metadata_invalid');

        return new ApiException(422, 'metadata_invalid', $message, [$field => [$message]]);
    }
}
