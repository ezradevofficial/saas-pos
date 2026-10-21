<?php

namespace App\Core\Marketplace\Http\Requests;

use Illuminate\Validation\Validator;

/** MKT-01: a new app: its slug (unique, public in its listing) and its first draft. */
class StoreDeveloperAppRequest extends DeveloperRequest
{
    use VersionContentRules;

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,58}[a-z0-9]$/'],
            ...$this->contentRules(required: true),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateContent($validator)];
    }
}
