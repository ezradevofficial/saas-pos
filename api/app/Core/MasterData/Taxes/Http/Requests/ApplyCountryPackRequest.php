<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

/** CP-01: copy the country pack's missing tax codes into the company. No body. */
class ApplyCountryPackRequest extends CompanyTaxRequest
{
    protected bool $edits = true;
}
