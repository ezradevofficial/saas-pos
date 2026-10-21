<?php

namespace Modules\Payroll\Http\Requests;

/**
 * POST payroll/statutory/{company}/not-applicable and DELETE
 * …/not-applicable/{component} (CP-01, PAY-08): `payroll.statutory.exempt`
 * at the company (Owner and Admin templates). The component must be one
 * the pack allows to be not applicable (checked by the controller); the
 * reason is kept with the mark and in the audit log.
 */
class NotApplicableRequest extends SettingsRequest
{
    public const EXEMPT = 'payroll.statutory.exempt';

    public function authorize(): bool
    {
        return $this->onCompany($this->company()->id, self::EXEMPT, 'payroll.settings.view');
    }

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return [];
        }

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
