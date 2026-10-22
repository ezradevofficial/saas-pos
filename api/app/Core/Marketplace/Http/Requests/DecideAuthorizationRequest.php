<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/**
 * MKT-02: the installing user approves (with the role the app acts as, at
 * one scope, RBAC-04) or denies an app's authorization request
 * (`core.marketplace.install`). Approving installs the app, or records a
 * new consent; either way the app is sent back with a code.
 */
class DecideAuthorizationRequest extends AuthorizeAppRequest
{
    protected string $permission = self::INSTALL;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'decision' => ['required', 'string', Rule::in(['approve', 'deny'])],
            'role_id' => ['required_if:decision,approve', 'nullable', 'uuid'],
            'scope_type' => ['required_if:decision,approve', 'nullable', 'string', Rule::in(Scope::TYPES)],
            'scope_id' => ['nullable', 'uuid'],
        ];
    }

    public function approves(): bool
    {
        return $this->validated('decision') === 'approve';
    }
}
