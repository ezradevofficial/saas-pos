<?php

namespace App\Core\Imports\Http\Requests;

/**
 * IMP-02, IMP-03: preview, dry run or import, by the person who uploaded
 * the file and may still run its importer in its mode.
 */
class ImportActionRequest extends ImportRequest
{
    protected string $ability = 'change';
}
