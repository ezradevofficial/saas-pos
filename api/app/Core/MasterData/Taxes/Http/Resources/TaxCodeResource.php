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
            'name' => $this->name(),
            'name_en' => $this->name_en,
            'name_fr' => $this->name_fr,
            'kind' => $this->kind,
            'pack_code' => $this->pack_code,
            'fiscal_code' => $this->fiscal_code,
            'current_rate' => $current?->toSummary(),
            'rate_needed' => ! $this->isExempt() && ($current === null || $current->isNeeded()),
            'rates' => $this->rates->map(fn (TaxRate $rate) => $rate->toSummary())->values()->all(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
