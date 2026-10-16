<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET shop/{store}/orders/{number} and its payment calls (ECOM-21): the tracking token, unless the order is the signed-in customer's. */
class TrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return ['t' => ['sometimes', 'nullable', 'string', 'size:40', 'alpha_num'], 'phone' => ['sometimes', 'nullable', 'string', 'max:32']];
    }
}
