<?php

namespace App\Core\CustomForms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CF-04: GET custom-form-files/{path}: an attachment behind a temporary
 * signed URL bound to one user (the `signed` middleware checks it; the
 * controller checks that user may still see the record).
 */
class CustomFormFileDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
