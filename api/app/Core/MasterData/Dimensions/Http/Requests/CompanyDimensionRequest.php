<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Tenancy\Models\Company;

/** MD-05: a company's departments, cost centres or projects (`core.dimension.*`, company scope). */
abstract class CompanyDimensionRequest extends CompanyResourceRequest
{
    use ResolvesDimensionType;

    protected string $resource = 'dimension';

    protected array $readActions = ['view', 'create', 'edit', 'archive'];

    protected function targetCompany(): Company
    {
        return $this->route('company');
    }
}
