<?php

namespace Modules\Procurement\Tenders;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Support\ProcurementError;
use RuntimeException;

/**
 * PRC-09: sealing with the tender's own key (ADR 019). Each tender gets a
 * random 256-bit key when it is created, stored encrypted with the
 * application key (`seal_secret`); bid contents and attachments are
 * encrypted with it (Laravel's Encrypter, AES-256-CBC with a MAC), and a
 * bid's fingerprint is the sha256 of its ciphertext. The payload names its
 * tender, bidder and version, checked at the opening.
 *
 * - key(): unwraps the tender key once; when the application key cannot
 *   (a rotated APP_KEY missing from APP_PREVIOUS_KEYS) it refuses with
 *   `seal_key_unavailable`, never marking bids tampered.
 * - open(): null only when the ciphertext does not match its fingerprint or
 *   fails its MAC (tampered); anything else is an error, not a verdict.
 * Nothing but the opening (Opening) and the opened bid's file download
 * calls open().
 */
final class Seal
{
    private const CIPHER = 'AES-256-CBC';

    /** A new tender's wrapped key. */
    public static function newSecret(): string
    {
        return Crypt::encryptString(base64_encode(Encrypter::generateKey(self::CIPHER)));
    }

    /** The tender's unwrapped key. */
    public static function key(Tender $tender): Encrypter
    {
        try {
            return new Encrypter(base64_decode(Crypt::decryptString($tender->seal_secret)), self::CIPHER);
        } catch (DecryptException) {
            throw ProcurementError::make('seal_key_unavailable', status: 500);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string} the ciphertext and its fingerprint
     */
    public static function seal(Encrypter $key, array $payload): array
    {
        $sealed = $key->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        return [$sealed, hash('sha256', $sealed)];
    }

    /** The bid's payload, or null when the ciphertext does not match its fingerprint or its MAC (tampered). */
    public static function open(Encrypter $key, string $sealed, string $fingerprint): ?array
    {
        if (! hash_equals($fingerprint, hash('sha256', $sealed))) {
            return null;
        }

        try {
            $plain = $key->decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }

        $payload = json_decode($plain, true);

        if (! is_array($payload)) {
            throw new RuntimeException('A sealed bid decrypted to something other than its payload.');
        }

        return $payload;
    }

    /** An attachment's bytes, sealed. */
    public static function sealBytes(Encrypter $key, string $bytes): string
    {
        return $key->encryptString(base64_encode($bytes));
    }

    /** The bytes, or null when the file fails its MAC (tampered). */
    public static function openBytes(Encrypter $key, string $sealed): ?string
    {
        try {
            $bytes = base64_decode($key->decryptString($sealed), true);
        } catch (DecryptException) {
            return null;
        }

        return $bytes === false ? null : $bytes;
    }

    /** ADR 019: the tender's secrets re-encrypted with the current application key (read with any of APP_PREVIOUS_KEYS). */
    public static function rewrap(?string $secret): ?string
    {
        return $secret === null ? null : Crypt::encryptString(Crypt::decryptString($secret));
    }
}
