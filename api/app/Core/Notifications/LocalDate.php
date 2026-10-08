<?php

namespace App\Core\Notifications;

use Carbon\CarbonInterface;

/** Dates in messages, in the recipient's language (L10N-01). */
final class LocalDate
{
    /** UTC, e.g. "12 Oct 2026 14:30 UTC" or "12 oct. 2026 14:30 UTC". */
    public static function format(CarbonInterface $at, ?string $locale = null): string
    {
        return $at->copy()->utc()->locale($locale ?? app()->getLocale())->isoFormat('D MMM YYYY HH:mm').' UTC';
    }
}
