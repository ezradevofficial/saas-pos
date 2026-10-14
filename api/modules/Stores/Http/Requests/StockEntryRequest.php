<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Stores\Stock\StockEntries;

/**
 * POST stores/stock-entries (STR-07): `stores.stock.adjust` at the store;
 * `{location_id, kind: opening|adjustment, reason (adjustments), note?,
 * lines: [{item_id, uom_id?, qty}]}` (opening quantities positive,
 * adjustments signed).
 */
class StockEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'stores.stock.adjust');
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')->whereNull('archived_at')],
            'kind' => ['required', 'string', Rule::in([StockEntries::OPENING, StockEntries::ADJUSTMENT])],
            'reason' => ['nullable', 'required_if:kind,'.StockEntries::ADJUSTMENT, 'string', Rule::in(StockEntries::REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
            'lines.*.uom_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('uoms', 'id')],
            'lines.*.qty' => ['required', 'string', 'regex:/^-?\d{1,14}(\.\d{1,6})?$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                StoreRequisitionRequest::at($this, 'stores.stock.adjust', (string) $this->input('location_id'));
            }
        }];
    }
}
