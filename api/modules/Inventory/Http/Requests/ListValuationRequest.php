<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Lists\ListDefinition;
use App\Core\Rbac\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Lists\ValuationList;

/**
 * GET inventory/valuation (INV-20): `inventory.valuation.view`; a
 * company's valuation is pooled over its locations, so it is listed for
 * holders at the company (or the tenant) only; `?company=`, `?category=`,
 * `?search=` (item code or name).
 */
class ListValuationRequest extends FormRequest
{
    use ListsInventory;

    public function list(): ListDefinition
    {
        return new ValuationList;
    }

    protected function viewPermission(): string
    {
        return 'inventory.valuation.view';
    }

    protected function extraRules(): array
    {
        return ['category' => ['sometimes', 'uuid', Rule::exists('item_categories', 'id')]];
    }

    /** The companies where the reader holds the permission at the company or above. */
    public function applyCompanies(Builder $query): Builder
    {
        $visible = $this->visible();

        if (! $visible->all) {
            $query->whereIn($query->qualifyColumn('company_id'), $visible->idsFor(Scope::COMPANY));
        }

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        return $query;
    }
}
