<?php

namespace App\Core\DocumentTemplates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** TPL-04: GET /d/{token}: the link's token is the only credential (checked by DocumentShares). */
class OpenSharedDocumentRequest extends FormRequest
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
