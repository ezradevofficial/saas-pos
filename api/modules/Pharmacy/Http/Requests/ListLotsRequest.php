<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Pharmacy\Http\Lists\LotList;
use Modules\Pharmacy\Http\StockQuery;
use Modules\Pharmacy\Models\LotBalance;

/**
 * GET pharmacy/lots (PHA-05, PHA-24): `pharmacy.lot.view`; stock by lot at
 * the reader's locations; `?item_id=`, `?state=` (expired, near_expiry,
 * quarantined, recalled, active), `?expires_within=` (days; expired lots
 * included), `?with_empty=1` (lots with no stock left), `?search=` (item
 * name or code, batch number), `?from=` and `?to=` are not used here.
 */
class ListLotsRequest extends FormRequest
{
    use ListsPharmacy;

    public function list(): ListDefinition
    {
        return new LotList;
    }

    protected function viewPermission(): string
    {
        return 'pharmacy.lot.view';
    }

    protected function extraRules(): array
    {
        return [
            'item_id' => ['sometimes', 'uuid', Rule::exists('items', 'id')],
            'state' => ['sometimes', 'string', Rule::in(StockQuery::STATES)],
            'expires_within' => ['sometimes', 'integer', 'between:0,3650'],
            'with_empty' => ['sometimes', 'boolean'],
        ];
    }

    /** @return Builder<LotBalance> */
    public function builder(): Builder
    {
        $query = StockQuery::balances($this->boolean('with_empty'));
        $this->applyFilters($query, 'location_id');

        if ($this->filled('item_id')) {
            $query->where('pharmacy_lot_balances.item_id', $this->validated('item_id'));
        }

        if ($this->filled('state')) {
            StockQuery::inState($query, $this->validated('state'));
        }

        if ($this->filled('expires_within')) {
            StockQuery::expiringWithin($query, (int) $this->validated('expires_within'));
        }

        $search = trim((string) $this->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q
                ->whereHas('lot', fn (Builder $lot) => $lot->where('pharmacy_lots.batch_number', 'ilike', $like))
                ->orWhereHas('item', fn (Builder $item) => $item->where('items.name', 'ilike', $like)->orWhere('items.code', 'ilike', $like)));
        }

        return $this->applySort($query);
    }
}
