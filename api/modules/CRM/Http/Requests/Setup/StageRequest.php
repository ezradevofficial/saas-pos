<?php

namespace Modules\CRM\Http\Requests\Setup;

use Modules\CRM\Http\Requests\SetupRequest;

/** CRM-02: PATCH crm/stages/{id} `{name?, probability?, position?}`; POST crm/stages/{id}/archive. */
class StageRequest extends SetupRequest
{
    protected function parameter(): ?string
    {
        return 'crm_stage';
    }

    public function rules(): array
    {
        return $this->route()?->getActionMethod() === 'updateStage' ? [
            'name' => ['sometimes', 'string', 'max:120'],
            'probability' => ['sometimes', 'integer', 'between:0,100'],
            'position' => ['sometimes', 'integer', 'min:1'],
        ] : [];
    }
}
