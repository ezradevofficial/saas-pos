<?php

namespace App\Core\Marketplace\Http\Requests;

use Illuminate\Validation\Validator;

/** MKT-01: edit the app's draft version (only a draft changes). */
class UpdateDraftRequest extends DeveloperRequest
{
    use VersionContentRules;

    public function rules(): array
    {
        return $this->contentRules(required: false);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateContent($validator)];
    }
}
