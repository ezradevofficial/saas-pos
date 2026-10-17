<?php

namespace App\Core\Sso\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** AUTH-11: enforce SSO, and JIT provisioning with its default role and scope. */
class UpdateSsoSettingsRequest extends SsoManageRequest
{
    public function rules(): array
    {
        return [
            'enforce' => ['sometimes', 'boolean'],
            'jit_provisioning' => ['sometimes', 'boolean'],
            'jit_role_id' => ['sometimes', 'nullable', 'uuid'],
            'jit_scope_type' => ['nullable', 'required_with:jit_scope_id', Rule::in(Scope::TYPES)],
            'jit_scope_id' => ['nullable', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'enforce' => __('sso.attributes.enforce'),
            'jit_provisioning' => __('sso.attributes.jit_provisioning'),
            'jit_role_id' => __('sso.attributes.jit_role_id'),
            'jit_scope_type' => __('sso.attributes.jit_scope_type'),
            'jit_scope_id' => __('sso.attributes.jit_scope_id'),
        ];
    }
}
