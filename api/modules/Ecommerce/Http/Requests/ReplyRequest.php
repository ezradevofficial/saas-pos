<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST ecommerce/conversations/{conversation}/reply (ECOM-25). */
class ReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return ['text' => ['required', 'string', 'max:4000']];
    }
}
