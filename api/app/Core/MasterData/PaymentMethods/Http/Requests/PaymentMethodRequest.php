<?php

namespace App\Core\MasterData\PaymentMethods\Http\Requests;

use App\Core\MasterData\Http\Requests\CompanyResourceRequest;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\Models\Company;

/** MD-04: one payment method, checked at its company's scope (`core.payment_method.*`). */
class PaymentMethodRequest extends CompanyResourceRequest
{
    protected string $resource = 'payment_method';

    protected array $readActions = ['view', 'create', 'edit', 'archive', 'configure'];

    protected function targetCompany(): Company
    {
        return Company::query()->findOrFail($this->paymentMethod()->company_id);
    }

    protected function paymentMethod(): PaymentMethod
    {
        return $this->route('payment_method');
    }
}
