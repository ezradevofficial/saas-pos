<?php

namespace Modules\HR\Http\Requests;

use App\Core\CustomFields\CustomFieldFiles;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HR\Models\Document;
use Modules\HR\Records\Documents;

/** POST hr/employees/{hr_employee}/documents (HR-27): `hr.document.manage` at the place and the category's view; multipart `file`. */
class StoreDocumentRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.document.manage');
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(Document::CATEGORIES)],
            'title' => ['required', 'string', 'max:200'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'file' => ['required', 'file', 'max:'.CustomFieldFiles::MAX_KB],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! in_array($this->input('category'), app(Documents::class)->visibleCategories($this->user(), $this->employee()), true)) {
                $this->forbid();
            }
        }];
    }
}
