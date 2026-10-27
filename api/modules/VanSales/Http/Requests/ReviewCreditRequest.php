<?php

namespace Modules\VanSales\Http\Requests;

/** POST van-sales/credit-reviews/{id}/review (VAN-12): `van_sales.credit.review` at the sale's van location. */
class ReviewCreditRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
