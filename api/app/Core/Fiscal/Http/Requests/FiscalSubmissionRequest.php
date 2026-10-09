<?php

namespace App\Core\Fiscal\Http\Requests;

use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Tenancy\Models\Company;

/**
 * One fiscal submission, checked at its company: `core.fiscal.view` to
 * read it (GET fiscal-submissions/{fiscal_submission}); RetryFiscalSubmissionRequest
 * needs `core.fiscal.edit`.
 */
class FiscalSubmissionRequest extends CompanyResourceRequest
{
    protected string $resource = 'fiscal';

    protected array $readActions = ['view', 'edit', 'configure'];

    protected function targetCompany(): Company
    {
        return Company::query()->findOrFail($this->submission()->company_id);
    }

    public function submission(): FiscalSubmission
    {
        return $this->route('fiscal_submission');
    }
}
