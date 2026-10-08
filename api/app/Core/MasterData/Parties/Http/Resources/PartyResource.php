<?php

namespace App\Core\MasterData\Parties\Http\Resources;

use App\Core\MasterData\Parties\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A party (MD-01). `credit_limit` is {amount_minor, currency} with the
 * amount as a string (ADR 003), or null.
 *
 * @mixin Party
 */
class PartyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'shared' => $this->isShared(),
            'kind' => $this->kind,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tax_id' => $this->tax_id,
            'roles' => $this->roles,
            'tags' => $this->tags,
            // jsonb reorders keys: give them back in a stable order.
            'phones' => self::ordered($this->phones, ['number', 'label']),
            'emails' => self::ordered($this->emails, ['address', 'label']),
            'addresses' => self::ordered($this->addresses, ['label', 'line1', 'line2', 'city', 'region', 'postal_code', 'country']),
            'currency' => $this->currency,
            'payment_terms_days' => $this->payment_terms_days,
            'credit_limit' => $this->creditLimit(),
            'price_list_id' => $this->price_list_id,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function ordered(?array $entries, array $keys): array
    {
        return array_map(
            fn (array $entry) => array_merge(array_fill_keys($keys, null), array_intersect_key($entry, array_flip($keys))),
            array_values($entries ?? []),
        );
    }
}
