<?php

namespace App\Core\DocumentTemplates\Http\Requests;

use App\Core\Configuration\ConfigKind;
use App\Core\DocumentTemplates\DocumentTypes;
use Closure;
use Illuminate\Validation\Rule;

/**
 * TPL-01: POST templates/preview {type, payload, scope_type?, scope_id?,
 * variant?}: the template rendered with sample data, with the problems
 * that would block publishing it.
 */
class PreviewTemplateRequest extends TemplateRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(DocumentTypes::keys())],
            'payload' => ['present', 'array', function (string $attribute, mixed $value, Closure $fail) {
                if (strlen((string) json_encode($value)) > ConfigKind::MAX_BYTES) {
                    $fail(__('config.errors.payload_too_large', ['kb' => intdiv(ConfigKind::MAX_BYTES, 1024)]));
                }
            }],
            'scope_type' => ['sometimes', 'string', 'in:tenant,company,branch'],
            'scope_id' => ['sometimes', 'nullable', 'uuid'],
            'variant' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /** The payload as sent (validated() keeps only the keys named in rules()). */
    public function payload(): array
    {
        return (array) $this->input('payload');
    }
}
