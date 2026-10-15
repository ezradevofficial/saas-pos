<?php

namespace Modules\Payroll\Http\Requests;

/** GET payroll/payment-formats (PAY-20): any holder of `payroll.run.view`. */
class ViewRequest extends PayrollRequest
{
    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'payroll.run.view');
    }
}
