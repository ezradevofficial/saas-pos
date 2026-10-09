<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\PaymentMethods\PaymentProviders;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * MD-04 payment method validation. Cash has a currency active in the
 * tenant; mobile money and card have a provider of their type, other types
 * none. `settings` and `secrets` take only the provider's keys (strings; a
 * null value clears a key on update). The type and provider are set at
 * creation: on update they may only be repeated unchanged.
 */
final class PaymentMethodRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(?PaymentMethod $method): array
    {
        $updating = $method !== null;
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'type' => $updating
                ? ['sometimes', 'string', Rule::in([$method->type])]
                : ['required', 'string', Rule::in(PaymentMethod::TYPES)],
            'provider' => $updating
                ? ['sometimes', 'nullable', 'string', Rule::in(array_filter([$method->provider]))]
                : ['sometimes', 'nullable', 'string', Rule::in(app(PaymentProviders::class)->names())],
            'name' => [...$required, 'string', 'max:100'],
            'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}\z/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'settings' => ['sometimes', 'array'],
            'settings.*' => ['nullable', 'string', 'max:255'],
            'secrets' => ['sometimes', 'array'],
            'secrets.*' => ['nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** Checks that need the type and provider (the method's own on update). */
    public static function validate(Validator $validator, array $input, ?PaymentMethod $method): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $providers = app(PaymentProviders::class);
        $type = $method?->type ?? $input['type'];
        $provider = $method === null ? ($input['provider'] ?? null) : $method->provider;
        $needsProvider = in_array($type, PaymentMethod::PROVIDER_TYPES, true);

        if ($method === null && $needsProvider && $provider === null) {
            $validator->errors()->add('provider', __('core.payment_method.provider_required'));

            return;
        }

        if ($method === null && ! $needsProvider && $provider !== null) {
            $validator->errors()->add('provider', __('core.payment_method.provider_not_allowed'));

            return;
        }

        if ($provider !== null && $providers->typeOf($provider) !== $type) {
            $validator->errors()->add('provider', __('core.payment_method.provider_other_type'));

            return;
        }

        $currency = array_key_exists('currency', $input) ? $input['currency'] : $method?->currency;

        if ($type === 'cash' && blank($currency)) {
            $validator->errors()->add('currency', __('core.payment_method.cash_currency_required'));
        }

        foreach (['settings' => $providers->settingKeys($provider), 'secrets' => $providers->secretKeys($provider)] as $field => $allowed) {
            $unknown = array_diff(array_map('strval', array_keys($input[$field] ?? [])), $allowed);

            if ($unknown !== []) {
                $validator->errors()->add($field, $allowed === []
                    ? __("core.payment_method.{$field}_none")
                    : __("core.payment_method.{$field}_unknown", ['keys' => implode(', ', $allowed)]));
            }
        }

        foreach ($providers->settingValues($provider) as $key => $values) {
            $value = $input['settings'][$key] ?? null;

            if ($value !== null && ! in_array($value, $values, true)) {
                $validator->errors()->add("settings.{$key}", __('core.payment_method.setting_value_invalid', ['key' => $key, 'values' => implode(', ', $values)]));
            }
        }
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'currency.exists' => __('core.currency.not_active'),
            'type.in' => __('core.payment_method.type_invalid'),
            'provider.in' => __('core.payment_method.provider_invalid'),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return collect(['type', 'provider', 'name', 'currency', 'settings', 'secrets', 'active'])
            ->mapWithKeys(fn (string $key) => [$key => __("core.payment_method.attributes.{$key}")])
            ->all();
    }
}
