<?php

namespace App\Core\Imports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * IMP-03: a result or error file behind a temporary signed URL (`signed`
 * middleware). The signature is the credential; ImportFileController
 * enters the tenant the path names and checks the signed-for user may
 * still see the import.
 */
class ImportFileRequest extends FormRequest
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
