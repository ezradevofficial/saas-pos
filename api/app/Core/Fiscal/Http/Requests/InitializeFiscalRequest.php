<?php

namespace App\Core\Fiscal\Http\Requests;

/**
 * POST companies/{company}/fiscal-settings/initialize (`core.fiscal.configure`):
 * register the company's device with the authority (eTIMS:
 * selectInitOsdcInfo) and keep the key it issues
 * (`core.fiscal.configure`: it writes the authority's credentials). No body.
 */
class InitializeFiscalRequest extends CompanyFiscalRequest
{
    protected bool $edits = true;

    protected string $action = 'configure';
}
