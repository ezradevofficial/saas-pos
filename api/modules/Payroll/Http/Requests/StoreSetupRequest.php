<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST payroll/pay-groups, payroll/items (PAY-03, PAY-04): creating a
 * company setup row needs `payroll.settings.manage` anywhere to try and at
 * the row's company (`company_id`) to succeed.
 */
class StoreSetupRequest extends PayrollRequest
{
    public const MANAGE = 'payroll.settings.manage';

    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), self::MANAGE);
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            ...($this->routeIs('payroll.pay-groups.store') ? PayGroupRequest::rulesFor(true) : ItemRequest::rulesFor(true)),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! $this->access()->at($this->user(), self::MANAGE, Scope::company((string) $this->input('company_id')))) {
                $this->forbid();
            }
        }];
    }
}
