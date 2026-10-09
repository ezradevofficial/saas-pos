<?php

namespace App\Core\DocumentTemplates\Http\Requests;

/** TPL-01: GET templates/types?scope_type=&scope_id=: the document types with their fields, for a scope. */
class ListTemplateTypesRequest extends TemplateRequest
{
    public function rules(): array
    {
        return [
            'scope_type' => ['sometimes', 'string', 'in:tenant,company,branch'],
            'scope_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
