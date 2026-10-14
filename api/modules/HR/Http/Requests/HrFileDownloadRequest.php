<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * HR-27: GET hr-files/{path}: a document behind a temporary signed URL
 * bound to one user (the `signed` middleware checks it; the controller
 * checks that user may still see the document).
 */
class HrFileDownloadRequest extends FormRequest
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
