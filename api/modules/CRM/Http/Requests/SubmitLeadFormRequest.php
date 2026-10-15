<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST crm/forms/{token} (CRM-06, public): the lead form's fields
 * (`name` always; the form's own required fields are checked against the
 * form once its token is known) and a honeypot (`website`) that must stay
 * empty. No authorisation: the token is the credential.
 */
class SubmitLeadFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'organisation' => ['sometimes', 'nullable', 'string', 'max:200'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[+0-9 ()\-]{3,30}$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'website' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
