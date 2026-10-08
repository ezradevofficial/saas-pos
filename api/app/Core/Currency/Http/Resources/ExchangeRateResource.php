<?php

namespace App\Core\Currency\Http\Resources;

use App\Core\Currency\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExchangeRate */
class ExchangeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pair' => "{$this->base}/{$this->quote}",
            // With `?pair=`: `direct` (stored as asked) or `inverse` (stored the other way); null otherwise.
            'direction' => $this->resource->direction,
            'base' => $this->base,
            'quote' => $this->quote,
            'kind' => $this->kind,
            'buy' => $this->buy,
            'sell' => $this->sell,
            'mid' => $this->mid,
            'effective_at' => $this->effective_at?->toIso8601String(),
            'source' => $this->source,
            'entered_by' => $this->entered_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
