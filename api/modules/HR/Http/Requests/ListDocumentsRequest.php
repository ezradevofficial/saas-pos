<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Records\Documents;

/** GET hr/employees/{hr_employee}/documents (HR-27): the categories the reader sees at the employee's place; `?status=active|archived|all`. */
class ListDocumentsRequest extends HrRequest
{
    public function authorize(): bool
    {
        abort_unless($this->access()->can($this->user(), 'hr.employee.view', $this->employee()), 404);

        return app(Documents::class)->visibleCategories($this->user(), $this->employee()) !== [];
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', 'string', 'in:active,archived,all']];
    }
}
