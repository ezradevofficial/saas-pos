<?php

namespace App\Core\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * APR-04: POST approvals/bulk-approve {ids: [...], comment?}: each request
 * is approved independently (only where its step allows bulk approval and
 * the user may decide it); the answer lists which were approved and why
 * the others were not.
 */
class BulkApproveRequest extends FormRequest
{
    public const MAX = 100;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['ids' => __('approvals.attributes.ids'), 'comment' => __('approvals.attributes.comment')];
    }
}
