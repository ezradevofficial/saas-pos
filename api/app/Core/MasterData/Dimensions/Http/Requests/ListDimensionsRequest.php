<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Dimensions\Dimension;
use App\Core\MasterData\Dimensions\Http\Lists\DimensionList;

/**
 * MD-05: a company's departments, cost centres or projects; `?status`,
 * `?per_page`, `?search=` (code or name), `?sort` (by code by default) and
 * an export (`?format`, `?columns[]`; DimensionList, EXP-01).
 */
class ListDimensionsRequest extends CompanyDimensionRequest
{
    use ListsRecords;

    private ?DimensionList $definition = null;

    public function list(): ListDefinition
    {
        return $this->definition ??= new DimensionList($this->dimensionType(), $this->route('company'), $this->user());
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /** @return class-string<Dimension> */
    public function model(): string
    {
        return $this->dimensionClass();
    }
}
