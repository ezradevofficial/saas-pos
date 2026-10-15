<?php

namespace Modules\Procurement\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** POST supplier-portal/notices/{notice}/register (PRC-08): public and rate-limited; `{name, email, tax_id?}`. */
class RegisterBidderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
