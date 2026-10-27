<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/reconciliations/{id} (FUEL-24): `fuel.variance.view` at the station. */
class ShowReconciliationRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.variance.view';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_reconciliation';
    }

    public function rules(): array
    {
        return [];
    }
}
