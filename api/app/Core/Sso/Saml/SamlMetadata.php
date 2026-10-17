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
 * public addresses and the request is pinned to them (no rebinding), no
 * redirects, a timeout and a size cap.
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
     * @return array{idp_entity_id: string, idp_sso_url: string, idp_certificates: list<string>}
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

        if (! $response->successful() || strlen($response->body()) > $max) {
            throw self::unreachable();
        }

        try {
            return $this->fromXml($response->body());
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

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
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
