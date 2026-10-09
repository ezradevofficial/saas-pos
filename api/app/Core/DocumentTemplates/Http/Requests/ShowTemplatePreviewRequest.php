<?php

namespace App\Core\DocumentTemplates\Http\Requests;

/** TPL-01: GET templates/previews/{preview}/pdf: a preview the same user made in the last minutes. */
class ShowTemplatePreviewRequest extends TemplateRequest
{
    public function rules(): array
    {
        return [];
    }
}
