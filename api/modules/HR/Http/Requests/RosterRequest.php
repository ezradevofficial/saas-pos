<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * GET/PATCH hr/rosters/{hr_roster}, PUT …/members, POST …/generate,
 * POST …/archive (HR-17): `hr.roster.manage` at the roster's branch (else
 * company) to change it.
 */
class RosterRequest extends SetupRowRequest
{
    public static function FIELDS(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id')],
            'department_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('departments', 'id')],
            'rotation_id' => [$required, 'uuid', Rule::exists('hr_rotations', 'id')],
            'anchor_date' => [$required, 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, mixed> */
    public static function MEMBERS(): array
    {
        return [
            'members' => ['present', 'array', 'max:500'],
            'members.*.employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')],
            'members.*.offset_days' => ['sometimes', 'integer', 'between:0,55'],
            'members.*.from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'members.*.to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function parameter(): string
    {
        return 'hr_roster';
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
        return $this->isMethod('PUT') ? self::MEMBERS() : self::FIELDS(false);
    }

    protected function actionFields(): array
    {
        return str_ends_with($this->path(), '/generate')
            ? ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]
            : [];
    }
}
