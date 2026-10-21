<?php

namespace App\Core\Api\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/**
 * API-02: a new key: its name, the role it holds at one scope (RBAC-04;
 * Grants checks the actor may grant it), its scopes and an optional
 * expiry within `api.keys.max_lifetime_days`.
 */
class StoreApiKeyRequest extends ApiKeyRequest
{
    use ScopesRules;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'role_id' => ['required', 'uuid'],
            'scope_type' => ['required', 'string', Rule::in(Scope::TYPES)],
            'scope_id' => ['nullable', 'required_unless:scope_type,'.Scope::TENANT, 'uuid'],
            ...$this->scopesRules(required: true),
            'expires_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays((int) config('api.keys.max_lifetime_days', 730))->toIso8601String()],
        ];
    }
}
