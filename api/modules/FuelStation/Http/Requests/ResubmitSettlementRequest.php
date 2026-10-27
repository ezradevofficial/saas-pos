<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/settlements/{id}/resubmit (FUEL-14): `fuel.variance.view` at the station; a settlement whose approval was cancelled goes back for approval. */
class ResubmitSettlementRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.variance.view';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_settlement';
    }

    public function rules(): array
    {
        return [];
    }
}
