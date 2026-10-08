<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Items\UomPolicy;
use Illuminate\Foundation\Http\FormRequest;

/** MD-02: a new unit of the tenant (`core.uom.edit` at tenant scope). */
class StoreUomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(UomPolicy::class)->edit($this->user());
    }

    protected function prepareForValidation(): void
    {
        $this->replace(UomRules::prepare($this->all()));
    }

    public function rules(): array
    {
        return UomRules::rules(updating: false);
    }

    public function attributes(): array
    {
        return UomRules::attributeNames();
    }

    public function messages(): array
    {
        return UomRules::messages();
    }
}
