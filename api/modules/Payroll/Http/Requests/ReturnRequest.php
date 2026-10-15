<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Payroll\Models\StatutoryReturn;

/**
 * Statutory returns (PAY-21), at their company: listing and downloading
 * need `payroll.return.view`, generating `payroll.return.generate`. A
 * return out of reach is not found.
 */
class ReturnRequest extends PayrollRequest
{
    public const VIEW = 'payroll.return.view';

    public const GENERATE = 'payroll.return.generate';

    public function statutoryReturn(): ?StatutoryReturn
    {
        $return = $this->route('payroll_return');

        return $return instanceof StatutoryReturn ? $return : null;
    }

    public function authorize(): bool
    {
        if (($return = $this->statutoryReturn()) !== null) {
            $this->onCompany($return->company_id, self::VIEW, self::VIEW);

            return $this->access()->at($this->user(), self::VIEW, Scope::company($return->company_id));
        }

        return $this->access()->anywhere($this->user(), $this->isMethod('POST') ? self::GENERATE : self::VIEW);
    }

    public function rules(): array
    {
        if ($this->statutoryReturn() !== null) {
            return [];
        }

        if ($this->isMethod('POST')) {
            return [
                'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
                'type' => ['required', 'string', 'max:40'],
                'period' => ['required', 'string', 'max:7'],
            ];
        }

        return [
            'company' => [$this->routeIs('payroll.returns.types') ? 'required' : 'sometimes', 'uuid', Rule::exists('companies', 'id')],
            'type' => ['sometimes', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->statutoryReturn() !== null) {
                return;
            }

            $company = $this->input($this->isMethod('POST') ? 'company_id' : 'company');

            if ($company !== null && ! $this->access()->at($this->user(), $this->isMethod('POST') ? self::GENERATE : self::VIEW, Scope::company((string) $company))) {
                $this->forbid();
            }
        }];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
