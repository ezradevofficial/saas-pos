<?php

namespace Modules\Manufacturing\Http\Requests;

use Carbon\CarbonImmutable;

/**
 * GET manufacturing/bom-versions/{id}/explode (MFG-05): not found unless
 * the user sees the BOM; `?qty=` (default the version's output quantity),
 * `?uom_id=` (a unit of the output item, default the version's), `?at=`
 * (ISO 8601, default now; the version's own date when earlier).
 */
class ExplodeRequest extends ShowBomRequest
{
    public function rules(): array
    {
        return [
            'qty' => ['sometimes', 'string', ManufacturingRules::QTY],
            'uom_id' => ['sometimes', 'uuid'],
            'at' => ['sometimes', 'date'],
        ];
    }

    public function at(): CarbonImmutable
    {
        return $this->filled('at') ? CarbonImmutable::parse((string) $this->validated('at')) : CarbonImmutable::now();
    }
}
