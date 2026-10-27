<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;

/** PUT van-sales/companies/{company}/settings (VAN-24): `van_sales.settings.edit` at the company. */
class UpdateSettingsRequest extends VanSalesRequest
{
    public function rules(): array
    {
        return [
            'gps_checkin' => ['sometimes', 'required', 'string', 'in:off,optional'],
            'gps_retention_days' => ['sometimes', 'required', 'integer', 'between:1,90'],
            'flag_offline_credit' => ['sometimes', 'required', 'boolean'],
        ];
    }

    protected function target(): ?Scope
    {
        return Scope::company((string) $this->route('company'));
    }
}
