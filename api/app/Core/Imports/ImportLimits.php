<?php

namespace App\Core\Imports;

/** IMP-03: how large a file may be. */
final class ImportLimits
{
    public const MAX_ROWS = 50000;

    public const MAX_KB = 20480;

    /** Uploads per user per minute (the `imports` rate limiter). */
    public const UPLOADS_PER_MINUTE = 10;
}
