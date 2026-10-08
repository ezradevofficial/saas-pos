<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\MasterData\Http\Requests\ListsArchivable;

/** MD-04: a company's payment methods in till order; `?status`, `?per_page`. */
class ListPaymentMethodsRequest extends CompanyPaymentMethodRequest
{
    use ListsArchivable;

    public function rules(): array
    {
        return $this->listRules();
    }
}
