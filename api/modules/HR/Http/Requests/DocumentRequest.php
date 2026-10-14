<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Document;
use Modules\HR\Models\Employee;
use Modules\HR\Records\Documents;

/**
 * GET hr/documents/{hr_document}/link and POST …/archive (HR-27): a
 * document the reader may not see (its category's permission at the
 * employee's place) is not found; archiving needs `hr.document.manage`.
 */
class DocumentRequest extends HrRequest
{
    public function document(): Document
    {
        return $this->route('hr_document');
    }

    public function employee(): Employee
    {
        return Employee::query()->findOrFail($this->document()->employee_id);
    }

    public function authorize(): bool
    {
        abort_unless(app(Documents::class)->sees($this->user(), $this->document(), $this->employee()), 404);

        return $this->isMethod('GET') || $this->access()->can($this->user(), 'hr.document.manage', $this->employee());
    }
}
