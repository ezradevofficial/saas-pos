<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;
use Modules\Inventory\Contracts\StockTransfers;

/** POST van-sales/reconciliations/{id}/unload/receive (VAN-23): `van_sales.unload.receive` at the warehouse the stock comes back to. */
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

    protected function target(): ?Scope
    {
        $count = $this->route('vs_count');
        $warehouse = $count->unload_transfer_id === null ? null : app(StockTransfers::class)->state($count->unload_transfer_id)['to_location_id'] ?? null;

        return $warehouse === null ? $count->scope() : Scope::location($warehouse);
    }
}
