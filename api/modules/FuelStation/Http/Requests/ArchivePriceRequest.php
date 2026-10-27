<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/prices/{id}/archive (FUEL-08): `fuel.price.manage` at the station; only a price that has not started. */
class ArchivePriceRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.price.manage';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_price';
    }

    public function rules(): array
    {
        return [];
    }
}
