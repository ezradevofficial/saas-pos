<?php

namespace App\Core\Imports\Http\Requests;

/** IMP-02: undo a batch (anyone who may run its importer in its mode). */
class UndoImportRequest extends ImportRequest
{
    protected string $ability = 'undo';
}
