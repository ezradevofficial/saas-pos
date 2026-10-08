<?php

namespace App\Core\MasterData\Taxes\Http\Resources;

use App\Core\MasterData\Taxes\PriceList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriceList */
class PriceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'currency' => $this->currency,
            'tax_inclusive' => $this->tax_inclusive,
            'is_default' => $this->is_default,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
