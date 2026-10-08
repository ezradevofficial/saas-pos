<?php

namespace App\Core\CountryPacks\Http\Resources;

use App\Core\CountryPacks\Models\CountryPack;
use App\Core\CountryPacks\Models\PackTaxCode;
use App\Core\CountryPacks\PackLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A country pack's version in force: name and code labels in the request
 * language (translation files, PackLabels), notes, sources, the figures
 * still to confirm, the change from the previous version (CP-03) and, when
 * loaded, its tax codes with their rates (null = "Rate needed").
 *
 * @mixin CountryPack
 */
class CountryPackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name(),
            'version' => $this->version,
            'published_at' => $this->published_at->toIso8601String(),
            'notes' => $this->summary['notes'] ?? null,
            'sources' => $this->summary['sources'] ?? [],
            'todo' => $this->summary['todo'] ?? [],
            'needs_confirmation' => $this->summary['needs_confirmation'] ?? [],
            'changes' => $this->summary['changes'] ?? null,
            'tax_codes' => $this->whenLoaded('taxCodes', fn () => $this->taxCodes->map(fn (PackTaxCode $code) => [
                'code' => $code->code,
                'name' => PackLabels::taxCode($this->code, $code->code),
                'kind' => $code->kind,
                'rate' => $code->rate,
                'needs_confirmation' => $code->needs_confirmation,
                'effective_from' => $code->effective_from->toDateString(),
                'effective_to' => $code->effective_to?->toDateString(),
                'fiscal_code' => $code->fiscal_code,
            ])->values()->all()),
        ];
    }
}
