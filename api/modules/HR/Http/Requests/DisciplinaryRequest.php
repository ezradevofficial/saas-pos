<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\HR\Models\DisciplinaryCase;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Sensitive;

/**
 * GET/PATCH hr/disciplinary-cases/{hr_disciplinary_case}, POST …/close
 * (HR-29): a case the reader may not see (the group's view at the
 * employee's place, not hidden by field rules) is not found.
 */
class DisciplinaryRequest extends HrRequest
{
    public static function FIELDS(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'category' => [$required, Rule::in(DisciplinaryCase::CATEGORIES)],
            'incident_date' => [$required, 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => [$required, 'string', 'max:5000'],
            'action_taken' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function case(): DisciplinaryCase
    {
        return $this->route('hr_disciplinary_case');
    }

    public function employee(): Employee
    {
        return Employee::query()->findOrFail($this->case()->employee_id);
    }

    public function authorize(): bool
    {
        abort_unless($this->sensitive()->sees($this->user(), $this->employee(), Sensitive::DISCIPLINARY, $this), 404);

        return $this->isMethod('GET') || $this->sensitive()->mayEdit($this->user(), $this->employee(), Sensitive::DISCIPLINARY);
    }

    public function rules(): array
    {
        return match (true) {
            $this->isMethod('PATCH') => self::FIELDS(false),
            $this->isMethod('POST') => ['outcome' => ['required', 'string', 'max:5000']],
            default => [],
        };
    }
}
