<?php

namespace App\Core\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * APR-08: GET and POST approvals/email/{token} {comment?}, without a
 * session: the 48-character single-use token is the credential
 * (EmailApprovals checks it, its expiry, the request and two-factor).
 */
class EmailApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['comment' => __('approvals.attributes.comment')];
    }
}
