<?php

namespace App\Core\Fiscal\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Tenancy\Models\Company;

/**
 * A company's fiscal settings and queue (`core.fiscal.view` to read,
 * `core.fiscal.edit` to change, at the company; RBAC-04). A company the
 * user cannot see is not found.
 */
abstract class CompanyFiscalRequest extends CompanyResourceRequest
{
    protected string $resource = 'fiscal';

    protected array $readActions = ['view', 'edit'];

    protected function targetCompany(): Company
    {
        return $this->route('company');
    }

    public function company(): Company
    {
        return $this->targetCompany();
    }
}
