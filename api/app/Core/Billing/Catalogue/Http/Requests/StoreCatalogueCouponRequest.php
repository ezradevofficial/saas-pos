<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;

/** SUB-01: a coupon. Its value is fixed once created; expiry and limit may change. */
class StoreCatalogueCouponRequest extends ManageCatalogueRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,40}$/', Rule::unique('catalogue_coupons', 'code')],
            'kind' => ['required', Rule::in(['percent', 'fixed'])],
            'percent' => ['required_if:kind,percent', 'prohibited_unless:kind,percent', 'nullable', 'integer', 'min:1', 'max:100'],
            'amount' => ['required_if:kind,fixed', 'prohibited_unless:kind,fixed', 'nullable', 'integer', 'min:1', 'max:'.StoreCataloguePriceRequest::MAX_AMOUNT],
            'currency' => ['required_if:kind,fixed', 'prohibited_unless:kind,fixed', 'nullable', 'string', Rule::exists('catalogue_countries', 'currency')],
            'applies_to_modules' => ['sometimes', 'array', 'max:40'],
            'applies_to_modules.*' => ['string', 'distinct', Rule::exists('catalogue_modules', 'key')],
            'applies_to_bundles' => ['sometimes', 'array', 'max:20'],
            'applies_to_bundles.*' => ['string', 'distinct', Rule::exists('catalogue_bundles', 'key')],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
