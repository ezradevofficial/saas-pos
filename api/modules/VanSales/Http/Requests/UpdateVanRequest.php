<?php

namespace Modules\VanSales\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT van-sales/vans/{id} (VAN-02): `van_sales.van.manage` at the van's location. */
class UpdateVanRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'plate' => ['sometimes', 'nullable', 'string', 'max:30'],
            'rep_id' => ['sometimes', 'required', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
            'device_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('devices', 'id')],
        ];
    }
}
