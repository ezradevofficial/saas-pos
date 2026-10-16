<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Order actions (ECOM-16 to ECOM-19): a reason to cancel or return, the cash collected, whether the fee goes back, a refund reference. */
class OrderActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => [...(in_array($this->route()?->getActionMethod(), ['cancel', 'return'], true) ? ['required'] : ['sometimes', 'nullable']), 'string', 'max:500'],
            'refund_fee' => ['sometimes', 'boolean'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'collected_minor' => ['sometimes', 'string', 'regex:/^\d{1,15}$/'],
        ];
    }
}
