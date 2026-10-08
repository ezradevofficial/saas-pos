<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Tenancy\Models\Company;

/**
 * WF-09, APR-05: a company's working hours. Read with `core.company.view`
 * reaching the company; changed with `core.company.edit` at it.
 */
class BusinessHoursRequest extends CompanyResourceRequest
{
    protected string $resource = 'company';

    protected array $readActions = ['view', 'edit'];

    protected function targetCompany(): Company
    {
        return $this->route('company');
    }
}
