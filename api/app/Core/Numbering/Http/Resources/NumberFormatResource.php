<?php

namespace App\Core\Numbering\Http\Resources;

use App\Core\Numbering\NumberFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NumberFormat */
class NumberFormatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'pattern' => $this->pattern,
            'reset' => $this->reset,
            'gapless' => $this->gapless,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
