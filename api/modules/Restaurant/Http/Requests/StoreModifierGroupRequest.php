<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST restaurant/modifier-groups (REST-07): `restaurant.modifier.manage` at the company. */
class StoreModifierGroupRequest extends RestaurantRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'currency' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            ...ModifierGroupFields::rules(true),
        ];
    }

    protected function target(): ?Scope
    {
        return Scope::company((string) $this->input('company_id'));
    }
}
