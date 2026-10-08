<?php

namespace App\Core\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * APR-03: an attachment behind a temporary signed URL (`signed`
 * middleware). The signature is the credential; AttachmentFileController
 * enters the file's tenant and checks the signed-for user may still see
 * the request.
 */
class AttachmentFileRequest extends FormRequest
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
