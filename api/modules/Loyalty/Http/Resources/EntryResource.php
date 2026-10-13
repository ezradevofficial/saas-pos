<?php

namespace Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\CreditEntry;
use Modules\Loyalty\Models\GiftCardEntry;
use Modules\Loyalty\Models\PointsEntry;

/** LOY-02, LOY-16, LOY-22: one ledger row (points, credit or gift card), with its running balance when given. */
class EntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PointsEntry|CreditEntry|GiftCardEntry $entry */
        $entry = $this->resource;
        $points = $entry instanceof PointsEntry;

        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'points' => $points ? (string) $entry->points : null,
            'amount' => $points ? null : ['amount_minor' => (string) $entry->amount_minor, 'currency' => $entry->currency],
            'balance' => $entry->getAttribute('running_balance'),
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
            'expires_at' => $points ? $entry->expires_at?->toIso8601String() : null,
            'source_type' => $entry->source_type,
            'sale_id' => $entry->sale_id,
            'reference' => $points ? null : $entry->reference,
            'note' => $points ? $entry->reason : ($entry instanceof CreditEntry ? $entry->note : null),
            'flags' => $entry->flags ?? [],
            'created_by' => $entry->relationLoaded('creator') && $entry->creator ? ['id' => $entry->creator->id, 'name' => $entry->creator->name] : null,
        ];
    }
}
