<?php

namespace App\Core\Api\Http\Requests;

/** API-02: rename a key or change its scopes (its role and scope stay: create another key). */
class UpdateApiKeyRequest extends ApiKeyRequest
{
    use ScopesRules;

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            ...$this->scopesRules(required: false),
        ];
    }
}
