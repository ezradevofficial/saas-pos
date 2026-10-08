<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

/** MD-03: rename a tax code, change its code or fiscal code. The kind never changes; rates go through `rates`. */
class UpdateTaxCodeRequest extends TaxCodeRequest
{
    protected bool $edits = true;

    protected function prepareForValidation(): void
    {
        TaxCodeRules::normaliseCode($this);
    }

    public function rules(): array
    {
        $code = $this->taxCode();

        return TaxCodeRules::rules($code->company_id, $code, updating: true);
    }

    public function attributes(): array
    {
        return TaxCodeRules::attributes();
    }
}
