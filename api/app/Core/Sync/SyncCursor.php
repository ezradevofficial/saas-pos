<?php

namespace App\Core\Sync;

use App\Core\Http\ApiException;

/**
 * NFR-04: the opaque position a device keeps per entity. Devices store and
 * send it back unchanged; its content is the server's business.
 *
 * Incremental: base64url("i1.{version}.{xid}.{seq}"), the last change
 * handed out, compared as the pair (sync_xid, sync_seq).
 * Snapshot: base64url("s1.{version}.{hash}"), the hash of the rows sent.
 *
 * A cursor of another source version means the entity starts again from
 * scratch (`reset: true`). A cursor that is not one of ours is refused
 * (422 `invalid_cursor`).
 */
final class SyncCursor
{
    private function __construct(
        public readonly string $kind,
        public readonly int $version,
        public readonly int $xid = 0,
        public readonly int $seq = 0,
        public readonly string $hash = '',
    ) {}

    public static function start(int $version): self
    {
        return new self('i', $version);
    }

    public static function at(int $version, int $xid, int $seq): self
    {
        return new self('i', $version, $xid, $seq);
    }

    public static function snapshot(int $version, string $hash): self
    {
        return new self('s', $version, hash: $hash);
    }

    public function encode(): string
    {
        $plain = $this->kind === 's'
            ? "s1.{$this->version}.{$this->hash}"
            : "i1.{$this->version}.{$this->xid}.{$this->seq}";

        return rtrim(strtr(base64_encode($plain), '+/', '-_'), '=');
    }

    /** @throws ApiException 422 `invalid_cursor` */
    public static function decode(string $entity, string $cursor): self
    {
        $plain = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (is_string($plain) && preg_match('/^i1\.(\d{1,9})\.(\d{1,19})\.(\d{1,19})$/', $plain, $m) === 1
            && self::fits($m[2]) && self::fits($m[3])) {
            return new self('i', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (is_string($plain) && preg_match('/^s1\.(\d{1,9})\.([0-9a-f]{64})$/', $plain, $m) === 1) {
            return new self('s', (int) $m[1], hash: $m[2]);
        }

        $message = __('core.sync.invalid_cursor', ['entity' => $entity]);

        throw new ApiException(422, 'invalid_cursor', $message, ["cursors.{$entity}" => [$message]], ['entity' => $entity]);
    }

    /** Within a signed bigint. */
    private static function fits(string $digits): bool
    {
        return strlen($digits) < 19 || strcmp($digits, '9223372036854775807') <= 0;
    }
}
