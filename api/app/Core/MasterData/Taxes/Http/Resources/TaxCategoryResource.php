<?php

namespace App\Core\MasterData\Taxes\Http\Resources;

use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tax category with its default tax code per company. The controller
 * loads `codes` filtered to the companies the user reaches (RBAC-04).
 *
 * @mixin TaxCategory
 */
class TaxCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'shared' => $this->isShared(),
            'name' => $this->name,
            'codes' => $this->codes
                ->sortBy('company_id')
                ->map(fn (TaxCategoryCode $code) => [
                    'company_id' => $code->company_id,
                    'tax_code_id' => $code->tax_code_id,
                    'code' => $code->taxCode?->code,
                ])->values()->all(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
