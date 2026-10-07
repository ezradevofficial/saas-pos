<?php

namespace App\Core\Tenancy\Http\Resources;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tax_id' => $this->tax_id,
            'country' => $this->country,
            'base_currency' => $this->base_currency,
            'fiscal_year_start_month' => $this->fiscal_year_start_month,
            'address' => (object) ($this->address ?? []),
            'timezone' => $this->timezone,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
