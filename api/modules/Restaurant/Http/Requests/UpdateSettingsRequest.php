<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/**
 * PUT restaurant/companies/{company}/settings (REST-13): `restaurant.settings.edit` at the company;
 * `{service_charge_percent (0 to 30), service_charge_item_id?, tip_item_id?, tip_presets?}`; the items are core items that are not stock.
 */
class UpdateSettingsRequest extends RestaurantRequest
{
    public function rules(): array
    {
        return [
            'service_charge_percent' => ['required', 'numeric', 'min:0', 'max:30', 'regex:/^\d{1,2}(\.\d{1,4})?$/'],
            'service_charge_item_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('items', 'id')],
            'tip_item_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('items', 'id')],
            'tip_presets' => ['sometimes', 'array', 'max:6'],
            'tip_presets.*' => ['integer', 'min:1', 'max:100'],
        ];
    }

    protected function target(): ?Scope
    {
        return Scope::company((string) $this->route('company')->getKey());
    }
}
