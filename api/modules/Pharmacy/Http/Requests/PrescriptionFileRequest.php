<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PHA-15: GET pharmacy-files/{path}: a prescription image behind a temporary
 * signed URL bound to one user (the `signed` middleware checks it; the
 * controller checks that user may still read the image).
 */
class PrescriptionFileRequest extends FormRequest
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
