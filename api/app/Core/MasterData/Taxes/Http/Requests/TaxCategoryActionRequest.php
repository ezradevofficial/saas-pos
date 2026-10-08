<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

/** TEN-06: archive or restore a tax category (`core.tax.edit` at its scope). No body. */
class TaxCategoryActionRequest extends TaxCategoryRequest
{
    protected bool $edits = true;
}
