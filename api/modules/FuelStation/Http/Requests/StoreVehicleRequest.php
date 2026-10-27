<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FuelStation\Models\Product;

/** POST fuel/vehicles (FUEL-20): `fuel.vehicle.manage` in the company. `{company_id, party_id, registration, label?, fuel_type?, daily_litre_limit?}`. */
class StoreVehicleRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.vehicle.manage';
    }

    protected function companyField(): ?string
    {
        return 'company_id';
    }

    public function rules(): array
    {
        return [
            'company_id' => FuelRules::company(),
            'party_id' => ['required', 'uuid', Rule::exists('parties', 'id')],
            'registration' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'fuel_type' => ['sometimes', 'nullable', 'string', Rule::in(Product::TYPES)],
            'daily_litre_limit' => FuelRules::litres(false, true),
        ];
    }
}
