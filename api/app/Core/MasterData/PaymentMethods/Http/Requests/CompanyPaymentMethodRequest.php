<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\Tenancy\Models\Company;

/** MD-04: a company's payment methods (`core.payment_method.*`, company scope). */
abstract class CompanyPaymentMethodRequest extends CompanyResourceRequest
{
    protected string $resource = 'payment_method';

    protected array $readActions = ['view', 'create', 'edit', 'archive'];

    protected function targetCompany(): Company
    {
        return $this->route('company');
    }
}
