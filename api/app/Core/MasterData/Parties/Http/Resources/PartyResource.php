<?php

namespace App\Core\MasterData\Parties\Http\Resources;

use App\Core\CustomFields\CustomFieldPresenter;
use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\FieldRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A party (MD-01). `credit_limit` is {amount_minor, currency} with the
 * amount as a string (ADR 003), or null. Fields hidden from the user by
 * field rules on `party` (RBAC-05) are left out, as in its history.
 *
 * @mixin Party
 */
class PartyResource extends JsonResource
{
    /** RBAC-05: the field rules resource; fields are named as the model's columns. */
    public const FIELD_RULES = 'party';

    /** Output keys built from several columns: hidden when any of them is. */
    public const SOURCES = ['credit_limit' => ['credit_limit_minor', 'credit_limit_currency']];

    public function toArray(Request $request): array
    {
        $hidden = $this->hiddenFields($request);

        return array_filter($this->fields($request), fn (string $key) => ! in_array($key, $hidden, true)
            && array_intersect(self::SOURCES[$key] ?? [], $hidden) === [], ARRAY_FILTER_USE_KEY);
    }

    /**
     * The fields hidden from the requesting user, read once per request
     * (lists render many parties).
     *
     * @return list<string>
     */
    private function hiddenFields(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        $key = 'field_rules.'.self::FIELD_RULES;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(FieldRules::class)->for($user, self::FIELD_RULES)['hidden']);
        }

        return $request->attributes->get($key);
    }

    /** @return array<string, mixed> */
    private function fields(Request $request): array
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
            // CF-03, RBAC-05: the custom values the user may see (CustomFieldPresenter).
            'custom' => (object) app(CustomFieldPresenter::class)->present($request->user(), PartyEntity::KEY, $this->custom),
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
