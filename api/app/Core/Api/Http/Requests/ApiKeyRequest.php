<?php

namespace App\Core\Api\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * API-02: a tenant's API keys are tenant-wide; reading and managing them
 * take `core.api_key.manage` at tenant scope (Owner template). A key never
 * manages keys: these routes are not public (ADR 020).
 */
class ApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('core.api_key.manage', Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
