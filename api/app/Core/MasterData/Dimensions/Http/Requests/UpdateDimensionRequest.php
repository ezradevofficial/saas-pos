<?php

namespace App\Core\MasterData\Dimensions\Http\Requests;

use App\Core\Tenancy\Models\Company;
use Illuminate\Validation\Validator;

/** MD-05: change a dimension's code, name, parent or owner (`core.dimension.edit`). Its company is set at creation. */
class UpdateDimensionRequest extends DimensionRequest
{
    protected bool $edits = true;

    protected function prepareForValidation(): void
    {
        DimensionRules::normalise($this);
    }

    public function rules(): array
    {
        return DimensionRules::rules($this->dimensionClass(), $this->dimension()->company_id, $this->dimension());
    }

    public function after(): array
    {
        return [fn (Validator $validator) => DimensionRules::validate(
            $validator, $this->all(), Company::query()->findOrFail($this->dimension()->company_id), $this->dimension(),
        )];
    }

    public function messages(): array
    {
        return DimensionRules::messages();
    }

    public function attributes(): array
    {
        return DimensionRules::attributes();
    }
}
