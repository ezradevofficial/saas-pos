<?php

namespace Modules\VanSales\Http\Requests;

use Modules\VanSales\Support\Places;

/** GET van-sales/companies/{company}/settings: anyone with a van sales permission in the company (the device reads them through the pull). */
class ShowSettingsRequest extends VanSalesRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        $places = app(Places::class);

        return $places->inCompany($this->user(), 'van_sales.van.view', $company->id) || $places->inCompany($this->user(), 'van_sales.settings.edit', $company->id);
    }
}
