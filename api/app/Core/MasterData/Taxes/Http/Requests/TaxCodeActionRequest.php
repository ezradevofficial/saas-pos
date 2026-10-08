<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

/** TEN-06: archive or restore a tax code (`core.tax.edit`). No body. */
class TaxCodeActionRequest extends TaxCodeRequest
{
    protected bool $edits = true;
}
