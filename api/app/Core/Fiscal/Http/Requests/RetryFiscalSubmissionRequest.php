<?php

namespace App\Core\Fiscal\Http\Requests;

/** POST fiscal-submissions/{fiscal_submission}/retry (`core.fiscal.edit` at its company). No body. */
class RetryFiscalSubmissionRequest extends FiscalSubmissionRequest
{
    protected bool $edits = true;
}
