<?php

namespace Modules\VanSales\Http\Requests;

use Illuminate\Validation\Rule;

/** POST van-sales/reconciliations/{id}/unload/receive (VAN-23): `van_sales.unload.receive` at the count's branch. */
class UnloadReceiveRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'nullable', 'array', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')],
            'lines.*.qty_received' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,6})?$/'],
        ];
    }
}
