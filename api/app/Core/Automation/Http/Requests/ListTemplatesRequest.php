<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Validation\Rule;

/** AUTO-07: GET automation-templates, optionally `?type=` (only templates usable with that document type). */
class ListTemplatesRequest extends AutomationViewRequest
{
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(app(DocumentTypeRegistry::class)->keys())],
        ];
    }
}
