<?php

namespace App\Core\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** APR-04, APR-06: GET approvals/document-types, for any signed-in user (no permission). */
class DocumentTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }
}
