<?php

namespace Modules\CRM\Http\Requests\Setup;

use Modules\CRM\Http\Requests\CrmRules;
use Modules\CRM\Http\Requests\SetupRequest;

/**
 * CRM-02: POST crm/pipelines `{company_id, name, is_default?, stages?:
 * [{name, probability}]}`; PATCH crm/pipelines/{id} `{name?,
 * is_default?}`; POST crm/pipelines/{id}/stages `{name, probability,
 * position?}`; POST crm/pipelines/{id}/archive|restore.
 */
class PipelineRequest extends SetupRequest
{
    protected function parameter(): ?string
    {
        return 'crm_pipeline';
    }

    public function rules(): array
    {
        return match ((string) $this->route()?->getActionMethod()) {
            'store' => [
                'company_id' => CrmRules::id('companies', required: true),
                'name' => ['required', 'string', 'max:120'],
                'is_default' => ['sometimes', 'boolean'],
                'stages' => ['sometimes', 'array', 'max:30'],
                'stages.*.name' => ['required', 'string', 'max:120'],
                'stages.*.probability' => ['required', 'integer', 'between:0,100'],
            ],
            'update' => ['name' => ['sometimes', 'string', 'max:120'], 'is_default' => ['sometimes', 'boolean']],
            'addStage' => ['name' => ['required', 'string', 'max:120'], 'probability' => ['required', 'integer', 'between:0,100'], 'position' => ['sometimes', 'integer', 'min:1']],
            default => [],
        };
    }
}
