<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

use App\Core\MasterData\Dimensions\Dimension;
use App\Core\MasterData\Http\Requests\ListsArchivable;

/** MD-05: a company's departments, cost centres or projects by code; `?status`, `?per_page`. */
class ListDimensionsRequest extends CompanyDimensionRequest
{
    use ListsArchivable;

    public function rules(): array
    {
        return $this->listRules();
    }

    /** @return class-string<Dimension> */
    public function model(): string
    {
        return $this->dimensionClass();
    }
}
