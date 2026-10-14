<?php

namespace Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\LoyaltySale;

/** LOY-12, H2: a POS sale as Loyalty processed it, with what it noticed. */
class LoyaltySaleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LoyaltySale $sale */
        $sale = $this->resource;

        return [
            'id' => $sale->id,
            'company_id' => $sale->company_id,
            'receipt_number' => $sale->receipt_number,
            'sold_at' => $sale->sold_at?->toIso8601String(),
            'party' => $sale->party_id === null ? null : ['id' => $sale->party_id, 'name' => $sale->relationLoaded('party') ? $sale->party?->name : null],
            'total' => ['amount_minor' => (string) $sale->total_minor, 'currency' => $sale->currency],
            'status' => $sale->status,
            'points_earned' => (string) $sale->points_earned,
            'flags' => array_values(array_map(fn ($flag) => ['code' => (string) ($flag['code'] ?? ''), 'detail' => $flag['detail'] ?? null], (array) ($sale->flags ?? []))),
            'voided' => $sale->voided_at !== null,
        ];
    }
}
