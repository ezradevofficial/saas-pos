<?php

namespace App\Core\DataExport;

use RuntimeException;

/** EXP-02: the export grew past `data_export.max_bytes`; it fails as `too_large`. */
class ExportTooLarge extends RuntimeException
{
    public function __construct(public readonly int $maxBytes)
    {
        parent::__construct("The export is larger than {$maxBytes} bytes.");
    }
}
