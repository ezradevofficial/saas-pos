<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\PaymentMethods\Http\Lists\PaymentMethodList;

/**
 * MD-04: a company's payment methods in till order; `?status`,
 * `?per_page`, `?search=` (name, unless field rules hide it), `?sort` and
 * an export (`?format`, `?columns[]`; PaymentMethodList, EXP-01: never
 * settings or credentials).
 */
class ListPaymentMethodsRequest extends CompanyPaymentMethodRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new PaymentMethodList($this->route('company'));
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
