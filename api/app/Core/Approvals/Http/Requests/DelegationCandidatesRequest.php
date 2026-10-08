<?php

namespace App\Core\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * APR-06: GET me/delegation-candidates?search=: whom the signed-in user
 * may delegate to (any user may delegate their own approvals; ordinary
 * approvers cannot list users).
 */
class DelegationCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
