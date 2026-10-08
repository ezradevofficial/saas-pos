<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

use App\Core\MasterData\Dimensions\Dimension;
use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Tenancy\Models\Company;

/** MD-05: one department, cost centre or project, checked at its company's scope (`core.dimension.*`). */
class DimensionRequest extends CompanyResourceRequest
{
    use ResolvesDimensionType;

    protected string $resource = 'dimension';

    protected array $readActions = ['view', 'create', 'edit', 'archive'];

    protected function targetCompany(): Company
    {
        return Company::query()->findOrFail($this->dimension()->company_id);
    }

    public function dimension(): Dimension
    {
        return $this->route($this->dimensionType());
    }
}
