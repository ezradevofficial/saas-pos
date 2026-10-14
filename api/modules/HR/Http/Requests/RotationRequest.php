<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** PATCH hr/rotations/{hr_rotation}, POST …/archive (HR-16): `days` lists a shift id or null (rest) per day of the cycle. */
class RotationRequest extends SetupRowRequest
{
    public static function FIELDS(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'days' => [$required, 'array', 'min:1', 'max:56'],
            'days.*' => ['present', 'nullable', 'uuid', Rule::exists('hr_shifts', 'id')],
        ];
    }

    protected function parameter(): string
    {
        return 'hr_rotation';
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
