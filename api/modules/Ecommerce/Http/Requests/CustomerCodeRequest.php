<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST shop/{store}/auth/code (ECOM-08): a phone number or email address, and a challenge's answer when one was asked. */
class CustomerCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'contact' => ['required', 'string', 'max:190'],
            // ECOM-08: the answer to a challenge the server issued (CodeChallenges).
            'challenge' => ['sometimes', 'array:id,solution'],
            'challenge.id' => ['required_with:challenge', 'string', 'max:40'],
            'challenge.solution' => ['required_with:challenge', 'string', 'max:32'],
        ];
    }
}
