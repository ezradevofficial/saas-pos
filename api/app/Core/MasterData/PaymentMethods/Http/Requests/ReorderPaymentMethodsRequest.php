<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

/** MD-04: put a company's active payment methods in a new till order (`core.payment_method.edit`): `ids`, every active method once. */
class ReorderPaymentMethodsRequest extends CompanyPaymentMethodRequest
{
    protected bool $edits = true;

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return ['ids' => __('core.payment_method.attributes.ids')];
    }
}
