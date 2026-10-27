<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/products/{id}/archive (FUEL-04, TEN-06): `fuel.setup.manage` for the whole company. */
class ArchiveProductRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function routeCompanyRecord(): ?string
    {
        return 'fuel_product';
    }

    protected function wide(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
