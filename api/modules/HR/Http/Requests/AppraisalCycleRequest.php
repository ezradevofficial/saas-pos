<?php

namespace Modules\HR\Http\Requests;

/** PATCH hr/appraisal-cycles/{hr_appraisal_cycle}, POST …/open, …/close (HR-30). */
class AppraisalCycleRequest extends SetupRowRequest
{
    public static function rulesFor(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'period_start' => [$required, 'date_format:Y-m-d'],
            'period_end' => [$required, 'date_format:Y-m-d'],
        ];
    }

    protected function parameter(): string
    {
        return 'hr_appraisal_cycle';
    }

    protected function viewPermission(): string
    {
        return 'hr.appraisal.view';
    }

    protected function managePermission(): string
    {
        return 'hr.appraisal.manage';
    }

    protected function fields(): array
    {
        return self::rulesFor(false);
    }
}
