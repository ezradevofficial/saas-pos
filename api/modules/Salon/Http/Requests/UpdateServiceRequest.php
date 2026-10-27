<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT salon/services/{id}: `salon.service.manage` at the service's company. */
class UpdateServiceRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'item_id' => ['sometimes', 'required', 'uuid', Rule::exists('items', 'id')],

            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'kind' => ['sometimes', 'required', 'string', 'in:service,addon'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'between:5,600'],
            'buffer_minutes' => ['sometimes', 'required', 'integer', 'between:0,120'],
            'online_bookable' => ['sometimes', 'required', 'boolean'],
            'staff_ids' => ['sometimes', 'array', 'max:200'],
            'staff_ids.*' => ['uuid', Rule::exists('salon_staff', 'id')],
            'resource_ids' => ['sometimes', 'array', 'max:200'],
            'resource_ids.*' => ['uuid', Rule::exists('salon_resources', 'id')],
            'addon_ids' => ['sometimes', 'array', 'max:50'],
            'addon_ids.*' => ['uuid', Rule::exists('salon_services', 'id')],
            'products' => ['sometimes', 'array', 'max:20'],
            'products.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')],
            'products.*.qty' => ['required', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
        ];
    }
}
