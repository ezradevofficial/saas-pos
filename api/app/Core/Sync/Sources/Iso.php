<?php

namespace App\Core\Sync\Sources;

use Carbon\CarbonImmutable;

/** Timestamps in sync payloads: ISO 8601 in UTC with microseconds, or null. */
final class Iso
{
    public static function of(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
