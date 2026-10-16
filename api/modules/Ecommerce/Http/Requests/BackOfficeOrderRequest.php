<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST ecommerce/orders and POST ecommerce/conversations/{conversation}/order (ECOM-22, ECOM-25): a phone or WhatsApp order taken by staff. */
class BackOfficeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => [...($this->route('ecom_conversation') === null ? ['required'] : ['prohibited']), 'uuid', Rule::exists('ecom_stores', 'id')],
            'channel' => ['sometimes', 'string', Rule::in(['phone', 'whatsapp'])],
            ...CartRules::cart(),
            ...CartRules::checkout(['mpesa', 'cod']),
        ];
    }
}
