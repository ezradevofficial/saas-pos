<?php

namespace App\Core\Fiscal\Http\Requests;

/**
 * POST companies/{company}/fiscal-settings/initialize (`core.fiscal.edit`):
 * register the company's device with the authority (eTIMS:
 * selectInitOsdcInfo) and keep the key it issues. No body.
 */
class InitializeFiscalRequest extends CompanyFiscalRequest
{
    protected bool $edits = true;
}
