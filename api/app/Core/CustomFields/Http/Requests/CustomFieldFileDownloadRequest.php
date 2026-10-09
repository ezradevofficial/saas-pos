<?php

namespace App\Core\CustomFields\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CF-01: a file field's file behind a temporary signed URL (`signed`
 * middleware). The signature is the credential; the controller enters the
 * file's tenant and checks the signed-for user may still see the record
 * and the field.
 */
class CustomFieldFileDownloadRequest extends FormRequest
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
