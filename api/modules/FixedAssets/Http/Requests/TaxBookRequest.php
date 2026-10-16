<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/companies/{company}/tax-book (FA-20): `fixed_assets.tax.view` at the company; `?year=` (this year by default). */
class TaxBookRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.tax.view';
    }

    public function rules(): array
    {
        return ['year' => ['sometimes', 'integer', 'between:2000,2100']];
    }
}
