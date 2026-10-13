<?php

namespace Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\Statement;

/** LOY-17: a stored statement (its lines come with its page). */
class StatementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Statement $statement */
        $statement = $this->resource;
        $money = fn ($minor) => ['amount_minor' => (string) $minor, 'currency' => $statement->currency];

        return [
            'id' => $statement->id,
            'number' => $statement->number,
            'account_id' => $statement->account_id,
            'party' => ['id' => $statement->party_id, 'name' => $statement->relationLoaded('party') ? $statement->party?->name : null],
            'period_from' => $statement->period_from->format('Y-m-d'),
            'period_to' => $statement->period_to->format('Y-m-d'),
            'opening' => $money($statement->opening_minor),
            'closing' => $money($statement->closing_minor),
            'ageing' => [
                'buckets' => array_map($money, (array) ($statement->ageing['buckets'] ?? [])),
                'open' => $money($statement->ageing['open_minor'] ?? '0'),
            ],
            'lines' => $this->when($statement->getAttribute('lines') !== null, fn () => $statement->getAttribute('lines')),
            'created_at' => $statement->created_at?->toIso8601String(),
        ];
    }
}
