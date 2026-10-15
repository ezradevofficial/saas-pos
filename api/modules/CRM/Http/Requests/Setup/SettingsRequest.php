<?php

namespace Modules\CRM\Http\Requests\Setup;

use Modules\CRM\Http\Requests\CrmRules;
use Modules\CRM\Http\Requests\SetupRequest;
use Modules\CRM\Support\CrmAccess;

/**
 * CRM-01: GET crm/companies/{company}/settings (any CRM permission at the
 * company) and PUT `{quote_approval_minor?, quote_validity_days?,
 * default_team_id?, lost_reason_required?}` (`crm.settings.manage`).
 */
class SettingsRequest extends SetupRequest
{
    protected function parameter(): ?string
    {
        return null;
    }

    public function authorize(): bool
    {
        if ($this->isMethod('GET')) {
            $reached = app(CrmAccess::class)->companies($this->user(), ...self::REACH);
            abort_unless($reached === null || in_array($this->company()?->id, $reached, true), 404);

            return true;
        }

        return parent::authorize();
    }

    public function rules(): array
    {
        return $this->isMethod('GET') ? [] : [
            'quote_approval_minor' => ['sometimes', 'nullable', 'string', CrmRules::MINOR],
            'quote_validity_days' => ['sometimes', 'integer', 'between:1,365'],
            'default_team_id' => CrmRules::id('crm_teams'),
            'lost_reason_required' => ['sometimes', 'boolean'],
        ];
    }
}
