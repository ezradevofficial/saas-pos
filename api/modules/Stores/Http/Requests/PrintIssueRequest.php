<?php

namespace Modules\Stores\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Stores\Prints\IssuePrints;

/**
 * GET stores/issues/{id}/print (STR-14, TPL-04): `stores.issue.view` at
 * the store (not found otherwise); `?document=delivery_note|issue_voucher`
 * (default delivery_note), `?format=html|pdf` (default html).
 */
class PrintIssueRequest extends ShowDocumentRequest
{
    public function rules(): array
    {
        return [
            'document' => ['sometimes', 'string', Rule::in(array_keys(IssuePrints::TYPES))],
            'format' => ['sometimes', 'string', Rule::in(['html', 'pdf'])],
        ];
    }

    public function documentType(): string
    {
        return IssuePrints::TYPES[(string) $this->validated('document', 'delivery_note')];
    }

    public function wantsPdf(): bool
    {
        return $this->validated('format', 'html') === 'pdf';
    }
}
