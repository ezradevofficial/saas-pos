<?php

namespace Modules\Payroll\Http\Requests;

/**
 * POST payroll/statutory/{company}/not-applicable (CP-01, PAY-08):
 * `payroll.settings.manage` at the company. The component must be one the
 * pack allows to be not applicable (checked by the controller); the reason
 * is kept with the mark and in the audit log.
 */
class NotApplicableRequest extends SettingsRequest
{
    public function rules(): array
    {
        return [
            'component' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [];
    }
}
