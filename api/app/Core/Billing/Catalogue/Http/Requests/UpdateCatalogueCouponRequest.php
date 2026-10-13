<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use App\Core\Billing\Catalogue\Models\CatalogueCoupon;

class UpdateCatalogueCouponRequest extends ManageCatalogueRequest
{
    public function rules(): array
    {
        /** @var CatalogueCoupon|null $coupon */
        $coupon = $this->route('catalogue_coupon');

        return [
            'code' => ['prohibited'],
            'kind' => ['prohibited'],
            'percent' => ['prohibited'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:'.max(1, (int) $coupon?->redemptions), 'max:1000000'],
        ];
    }
}
