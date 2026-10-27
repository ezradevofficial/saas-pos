<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT restaurant/modifier-groups/{id} (REST-07): `restaurant.modifier.manage` at the group's company. */
class UpdateModifierGroupRequest extends BoundRequest
{
    public function rules(): array
    {
        return [...ModifierGroupFields::rules(false), 'currency' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')]];
    }
}
