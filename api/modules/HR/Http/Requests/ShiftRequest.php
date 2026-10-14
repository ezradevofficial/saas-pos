<?php

namespace Modules\HR\Http\Requests;

/** PATCH hr/shifts/{hr_shift}, POST …/archive (HR-16). */
class ShiftRequest extends SetupRowRequest
{
    public static function FIELDS(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40'],
            'name' => [$required, 'string', 'max:120'],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i', 'different:start_time'],
            'break_minutes' => ['sometimes', 'integer', 'between:0,720'],
        ];
    }

    protected function parameter(): string
    {
        return 'hr_shift';
    }

    protected function viewPermission(): string
    {
        return 'hr.roster.view';
    }

    protected function managePermission(): string
    {
        return 'hr.roster.manage';
    }

    protected function fields(): array
    {
        return self::FIELDS(false);
    }
}
