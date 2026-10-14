<?php

namespace Modules\Stores\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Stores\Models\StoreReturn;

/**
 * POST stores/issues/{id}/returns (STR-06): not found unless the user sees
 * the issue; `stores.return.create` at its store (403);
 * `{reason, note?, lines: [{issue_line_id, qty}]}` (a line of this issue, a
 * positive decimal string in the line's unit).
 */
class ReturnRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('stores.return.create');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(StoreReturn::REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.issue_line_id' => ['required', 'uuid', Rule::exists('stores_issue_lines', 'id')->where('issue_id', $this->document()->getKey())],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
        ];
    }
}
