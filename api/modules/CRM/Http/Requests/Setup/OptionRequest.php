<?php

namespace Modules\CRM\Http\Requests\Setup;

use Illuminate\Validation\Rule;
use Modules\CRM\Http\Requests\CrmRules;
use Modules\CRM\Http\Requests\SetupRequest;
use Modules\CRM\Models\Option;

/** CRM-03: POST crm/options `{company_id, kind, name, position?}`; PATCH crm/options/{id} `{name?, position?}`; archive and restore. */
class OptionRequest extends SetupRequest
{
    protected function parameter(): ?string
    {
        return 'crm_option';
    }

    public function rules(): array
    {
        return match ((string) $this->route()?->getActionMethod()) {
            'store' => [
                'company_id' => CrmRules::id('companies', required: true),
                'kind' => ['required', 'string', Rule::in(Option::KINDS)],
                'name' => ['required', 'string', 'max:120'],
                'position' => ['sometimes', 'integer', 'min:0'],
            ],
            'update' => ['name' => ['sometimes', 'string', 'max:120'], 'position' => ['sometimes', 'integer', 'min:0']],
            default => [],
        };
    }
}
