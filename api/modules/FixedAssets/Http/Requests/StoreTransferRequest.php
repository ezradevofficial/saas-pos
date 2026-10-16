<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;

/** POST fixed-assets/assets/{id}/transfers (FA-14): `fixed_assets.transfer.request` at the asset's location; `{to_location_id?, to_custodian_user_id?, to_custodian_employee_id?, to_cost_centre_id?, transfer_date, reason}`. */
class StoreTransferRequest extends AssetRequest
{
    public function rules(): array
    {
        return [
            'to_location_id' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'to_custodian_user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
            'to_custodian_employee_id' => ['sometimes', 'nullable', 'uuid', StoreAssetRequest::employee()],
            'to_cost_centre_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('cost_centres', 'id')],
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.transfer.request';
    }
}
