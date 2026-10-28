<?php

namespace App\Core\Payments\Pawapay;

use App\Core\Payments\ProviderUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Verifies pawaPay's signed callbacks (RFC 9421 HTTP message signatures,
 * ECDSA P-256 with SHA-256 or RSA), only when `payments.pawapay.verify_signatures`
 * is on (signed callbacks must be enabled in the pawaPay dashboard first).
 *
 * 1. Content-Digest must match the raw body (sha-256 or sha-512).
 * 2. The signature base is rebuilt from the components Signature-Input
 *    lists (@method, @authority, @path, headers) and its parameters.
 * 3. The key is pawaPay's public key named by `keyid`
 *    (GET /v2/public-key/http, cached for an hour; fetched again once when
 *    the key id is unknown).
 *
 * A callback that fails any step is refused (401). A passing signature is
 * never enough to mark anything paid: the status is always re-checked.
 */
class PawapaySignatures
{
    public function enabled(): bool
    {
        return (bool) config('payments.pawapay.verify_signatures', false);
    }

    public function valid(Request $request): bool
    {
        $input = (string) $request->header('Signature-Input');
        $signature = (string) $request->header('Signature');

        if (preg_match('/^([\w-]+)=(\(([^)]*)\)(.*))$/', $input, $m) !== 1) {
            return false;
        }

        [, $label, $params, $components, $rest] = $m;

        if (preg_match('/(?:^|,\s*)'.preg_quote($label, '/').'=:([A-Za-z0-9+\/=]+):/', $signature, $s) !== 1) {
            return false;
        }

        if (! $this->digestMatches($request)) {
            return false;
        }

        $base = $this->base($request, $components, $params);
        $alg = preg_match('/;alg="([^"]+)"/', $rest, $a) === 1 ? $a[1] : null;
        $keyId = preg_match('/;keyid="([^"]+)"/', $rest, $k) === 1 ? $k[1] : null;

        if ($base === null || $alg === null || $keyId === null) {
            return false;
        }

        if (preg_match('/;expires=(\d+)/', $rest, $e) === 1 && (int) $e[1] < time()) {
            return false;
        }

        $pem = $this->keyFor($keyId);

        return $pem !== null && $this->verify($base, (string) base64_decode($s[1], true), $pem, $alg);
    }

    private function digestMatches(Request $request): bool
    {
        $header = (string) $request->header('Content-Digest');
        $body = $request->getContent();

        foreach (['sha-256' => 'sha256', 'sha-512' => 'sha512'] as $name => $algorithm) {
            if (preg_match('/'.$name.'=:([A-Za-z0-9+\/=]+):/', $header, $m) === 1) {
                return hash_equals(base64_encode(hash($algorithm, $body, true)), $m[1]);
            }
        }

        return false;
    }

    private function base(Request $request, string $components, string $params): ?string
    {
        $lines = [];

        foreach (preg_split('/\s+/', trim($components)) ?: [] as $quoted) {
            $name = trim($quoted, '"');

            $value = match ($name) {
                '@method' => strtoupper($request->method()),
                '@authority' => strtolower($request->getHttpHost()),
                '@path' => $request->getPathInfo(),
                '@target-uri' => $request->fullUrl(),
                default => $request->headers->has($name) ? trim((string) $request->header($name)) : null,
            };

            if ($value === null) {
                return null;
            }

            $lines[] = '"'.$name.'": '.$value;
        }

        // The signature parameters: the component list and what follows it, as sent.
        preg_match('/^[\w-]+=(.*)$/', (string) $request->header('Signature-Input'), $p);
        $lines[] = '"@signature-params": '.($p[1] ?? '('.$components.')'.$params);

        return implode("\n", $lines);
    }

    private function verify(string $base, string $signature, string $pem, string $alg): bool
    {
        if ($signature === '') {
            return false;
        }

        return match ($alg) {
            'ecdsa-p256-sha256' => openssl_verify($base, self::derFromRaw($signature, 32), $pem, OPENSSL_ALGO_SHA256) === 1,
            'ecdsa-p384-sha384' => openssl_verify($base, self::derFromRaw($signature, 48), $pem, OPENSSL_ALGO_SHA384) === 1,
            'rsa-v1_5-sha256' => openssl_verify($base, $signature, $pem, OPENSSL_ALGO_SHA256) === 1,
            default => false,
        };
    }

    /** RFC 9421 ECDSA signatures are r||s; OpenSSL wants DER. */
    public static function derFromRaw(string $raw, int $size): string
    {
        if (strlen($raw) !== $size * 2) {
            return $raw;
        }

        $int = function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            $bytes = $bytes === '' || ord($bytes[0]) > 0x7F ? "\x00".$bytes : $bytes;

            return "\x02".chr(strlen($bytes)).$bytes;
        };
        $body = $int(substr($raw, 0, $size)).$int(substr($raw, $size));

        return "\x30".(strlen($body) < 128 ? chr(strlen($body)) : "\x81".chr(strlen($body))).$body;
    }

    private function keyFor(string $keyId): ?string
    {
        $cached = Cache::get('pawapay:public-keys');

        foreach ([is_array($cached) ? $cached : null, null] as $keys) {
            if ($keys === null) {
                try {
                    $keys = (new PawapayClient(PawapayCredentials::operator()))->publicKeys();
                } catch (ProviderUnavailable) {
                    return null;
                }

                Cache::put('pawapay:public-keys', $keys, 3600);
            }

            foreach ($keys as $key) {
                if (($key['id'] ?? null) === $keyId) {
                    return $key['key'];
                }
            }
        }

        return null;
    }
}
