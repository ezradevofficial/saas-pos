<?php

namespace App\Core\MasterData\PaymentMethods\Http\Resources;

use App\Core\MasterData\Items\Http\Resources\HidesFields;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\PaymentMethods\PaymentProviders;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment method (MD-04). Secret values are never returned: `secrets_set`
 * names the secret keys that hold a value. `missing` lists the provider
 * keys still needed before the method can be switched on. Fields hidden by
 * field rules on `payment_method` (RBAC-05) are left out, as in its history.
 *
 * @mixin PaymentMethod
 */
class PaymentMethodResource extends JsonResource
{
    public const FIELD_RULES = 'payment_method';

    public function toArray(Request $request): array
    {
        $missing = app(PaymentProviders::class)->missing($this->resource);

        $fields = [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'type' => $this->type,
            'name' => $this->name(),
            'name_en' => $this->name_en,
            'name_fr' => $this->name_fr,
            'currency' => $this->currency,
            'provider' => $this->provider,
            'settings' => (object) ($this->settings ?? []),
            'secrets_set' => (object) $this->secretsSet(),
            'configured' => $missing === [],
            'missing' => $missing,
            'active' => $this->active,
            'position' => $this->position,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        return HidesFields::apply($request, self::FIELD_RULES, $fields, [
            'name' => ['name_en', 'name_fr'],
            'secrets_set' => ['secrets'],
            'configured' => ['settings', 'secrets'],
            'missing' => ['settings', 'secrets'],
        ]);
    }
}
