<?php

namespace App\Core\MasterData\Items\Http\Requests;

/** MD-02: change a unit's code, names or kind (`core.uom.edit` at tenant scope). */
class UpdateUomRequest extends UomRequest
{
    protected bool $edits = true;

    protected function prepareForValidation(): void
    {
        $this->replace(UomRules::prepare($this->all()));
    }

    public function rules(): array
    {
        return UomRules::rules(updating: true);
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
