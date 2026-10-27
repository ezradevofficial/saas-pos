<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/reconciliations/{id}/resubmit (FUEL-26): `fuel.variance.view` at the station; a reconciliation whose approval was cancelled goes back for approval. */
class ResubmitReconciliationRequest extends FuelRequest
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
