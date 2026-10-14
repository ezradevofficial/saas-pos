<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** PATCH hr/positions/{hr_position}, POST …/archive, …/restore (HR-08). */
class PositionRequest extends SetupRowRequest
{
    public static function FIELDS(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40'],
            'title' => [$required, 'string', 'max:150'],
            'department_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('departments', 'id')],
            'grade' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    protected function parameter(): string
    {
        return 'hr_position';
    }

    protected function viewPermission(): string
    {
        return 'hr.position.view';
    }

    protected function managePermission(): string
    {
        return 'hr.position.manage';
    }

    protected function fields(): array
    {
        return self::FIELDS(false);
    }
}
