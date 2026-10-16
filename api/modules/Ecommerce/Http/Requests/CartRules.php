<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Validation\Rule;

/** ECOM-09 to ECOM-15: the rules a cart and a checkout share (money is never read from the client, ECOM-12). */
final class CartRules
{
    /** @return array<string, list<mixed>> */
    public static function cart(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            // Under the tenant's row-level security: another tenant's ids do not exist (422).
            'lines.*.product_id' => ['required', 'uuid', Rule::exists('ecom_products', 'id')],
            'lines.*.qty' => ['required', 'string', 'max:12'],
            'fulfilment' => ['sometimes', 'string', Rule::in(['delivery', 'pickup'])],
            'zone_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('ecom_zones', 'id')],
            'pickup_location_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('locations', 'id')],
            'coupon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'points' => ['sometimes', 'nullable', 'string', 'regex:/^\\d{1,10}$/'],
            'gift_card' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @param  list<string>  $payments
     * @return array<string, list<mixed>>
     */
    public static function checkout(array $payments): array
    {
        return [
            'payment' => ['required', 'string', Rule::in($payments)],
            'contact' => ['required', 'array'],
            'contact.name' => ['required', 'string', 'max:160'],
            'contact.phone' => ['required', 'string', 'max:32'],
            'contact.email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.line' => ['sometimes', 'nullable', 'string', 'max:300'],
            'address.area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address.notes' => ['sometimes', 'nullable', 'string', 'max:300'],
            'pay_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'expected_total_minor' => ['required', 'string', 'regex:/^\\d{1,15}$/'],
        ];
    }
}
