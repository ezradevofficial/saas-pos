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
 * keys still needed before the method can be switched on; `setting_keys`
 * and `secret_keys` name every key its provider takes (names only, so a
 * settings form knows which fields are plain and which are secret). Fields hidden by
 * field rules on `payment_method` (RBAC-05) are left out, as in its history.
 *
 * @mixin PaymentMethod
 */
class PaymentMethodResource extends JsonResource
{
    public const FIELD_RULES = 'payment_method';

    /** Output keys built from secret or setting columns: hidden when any of them is (RBAC-05). */
    public const SOURCES = [
        'secrets_set' => ['secrets'],
        'setting_keys' => ['settings'],
        'secret_keys' => ['secrets'],
        'configured' => ['settings', 'secrets'],
        'missing' => ['settings', 'secrets'],
    ];

    public function toArray(Request $request): array
    {
        $providers = app(PaymentProviders::class);
        $missing = $providers->missing($this->resource);

        $fields = [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'type' => $this->type,
            'name' => $this->name,
            'currency' => $this->currency,
            'provider' => $this->provider,
            'settings' => (object) ($this->settings ?? []),
            'secrets_set' => (object) $this->secretsSet(),
            'setting_keys' => $providers->settingKeys($this->provider),
            'secret_keys' => $providers->secretKeys($this->provider),
            'configured' => $missing === [],
            'missing' => $missing,
            'active' => $this->active,
            'position' => $this->position,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        return HidesFields::apply($request, self::FIELD_RULES, $fields, self::SOURCES);
    }
}
