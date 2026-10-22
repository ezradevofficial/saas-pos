<?php

namespace App\Core\MasterData\Taxes\Http\Resources;

use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tax code with its rate history and the rate in force today in the
 * company's time zone. `rate_needed` is true when that rate is missing or
 * not confirmed (shown as "Rate needed"); never for exempt codes.
 *
 * @mixin TaxCode
 */
class TaxCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // rateOn resolves today in the company's time zone.
        $current = $this->isExempt() ? null : $this->rateOn(CarbonImmutable::now());

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'pack_code' => $this->pack_code,
            'fiscal_code' => $this->fiscal_code,
            'current_rate' => $current?->toSummary(),
            'rate_needed' => ! $this->isExempt() && ($current === null || $current->isNeeded()),
            // CP-01: the rate in force comes from public research, not yet confirmed.
            'rate_researched' => $current !== null && $current->isResearched(),
            // CP-01: researched at low confidence: not confirmed by an accountant (the stronger notice).
            'rate_low_confidence' => $current !== null && $current->isLowConfidence(),
            'rates' => $this->rates->map(fn (TaxRate $rate) => $rate->toSummary())->values()->all(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
