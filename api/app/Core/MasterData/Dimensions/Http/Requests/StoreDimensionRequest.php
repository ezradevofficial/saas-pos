<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

use App\Core\MasterData\Dimensions\Dimension;
use Illuminate\Validation\Validator;

/** MD-05: a new department, cost centre or project of the company (`core.dimension.create`). */
class StoreDimensionRequest extends CompanyDimensionRequest
{
    protected bool $edits = true;

    protected string $action = 'create';

    protected function prepareForValidation(): void
    {
        DimensionRules::normalise($this);
    }

    public function rules(): array
    {
        return DimensionRules::rules($this->dimensionClass(), $this->route('company')->id, null);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => DimensionRules::validate($validator, $this->all(), $this->route('company'), null)];
    }

    public function messages(): array
    {
        return DimensionRules::messages();
    }

    public function attributes(): array
    {
        return DimensionRules::attributes();
    }

    /** @return class-string<Dimension> */
    public function model(): string
    {
        return $this->dimensionClass();
    }
}
