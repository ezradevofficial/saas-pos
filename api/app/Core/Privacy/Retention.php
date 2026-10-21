<?php

namespace App\Core\Privacy;

/**
 * NFR-08 (review M3): what a module needs kept when a subject is
 * anonymised, and why.
 *
 * - `keep`: table => column => `keep` (left as it is) or `copy:<column>`
 *   (set from another column of the same row before the rest is cleared).
 * - `blockedBy`: an error code when anonymisation must wait (fiscal
 *   documents still being sent); null when it may go ahead.
 * - `reason`: the legal obligation, shown in the request and the access
 *   report ("retained for legal obligation").
 */
final class Retention
{
    /** @param array<string, array<string, string>> $keep */
    public function __construct(
        public readonly array $keep,
        public readonly string $reason,
        public readonly ?string $blockedBy = null,
    ) {}
}
