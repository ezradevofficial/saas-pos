<?php

namespace Modules\Stores\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST stores/requisitions/{id}/issues (STR-04): not found unless the user
 * sees the requisition; `stores.issue.create` at its store (403);
 * `{note?, lines: [{requisition_line_id, qty}]}` (a line of this
 * requisition, a positive decimal string in the line's unit).
 */
class IssueRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('stores.issue.create');
    }

    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.requisition_line_id' => ['required', 'uuid', Rule::exists('stores_requisition_lines', 'id')->where('requisition_id', $this->document()->getKey())],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
        ];
    }
}
