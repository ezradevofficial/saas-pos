<?php

namespace Modules\Procurement\Tenders;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Modules\Procurement\Models\Tender;

/**
 * PRC-09: sealing with the tender's own key. Each tender gets a random
 * 256-bit key when it is created, stored encrypted with the application
 * key (`seal_secret`); bid contents and attachments are encrypted with it
 * (Laravel's Encrypter, AES-256-CBC with a MAC), and a bid's fingerprint is
 * the sha256 of its ciphertext. Nothing but the opening (Opening) and the
 * bidder's own view of its sealed bid (PortalBids) calls open().
 */
final class Seal
{
    private const CIPHER = 'AES-256-CBC';

    /** A new tender's wrapped key. */
    public static function newSecret(): string
    {
        return Crypt::encryptString(base64_encode(Encrypter::generateKey(self::CIPHER)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string} the ciphertext and its fingerprint
     */
    public static function seal(Tender $tender, array $payload): array
    {
        $sealed = self::encrypter($tender)->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        return [$sealed, hash('sha256', $sealed)];
    }

    /** The bid's payload, or null when the ciphertext does not match its fingerprint or does not decrypt (tampered). */
    public static function open(Tender $tender, string $sealed, string $fingerprint): ?array
    {
        if (! hash_equals($fingerprint, hash('sha256', $sealed))) {
            return null;
        }

        try {
            $payload = json_decode(self::encrypter($tender)->decryptString($sealed), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /** An attachment's bytes, sealed. */
    public static function sealBytes(Tender $tender, string $bytes): string
    {
        return self::encrypter($tender)->encryptString(base64_encode($bytes));
    }

    public static function openBytes(Tender $tender, string $sealed): ?string
    {
        try {
            $bytes = base64_decode(self::encrypter($tender)->decryptString($sealed), true);
        } catch (DecryptException) {
            return null;
        }

        return $bytes === false ? null : $bytes;
    }

    private static function encrypter(Tender $tender): Encrypter
    {
        return new Encrypter(base64_decode(Crypt::decryptString($tender->seal_secret)), self::CIPHER);
    }
}
