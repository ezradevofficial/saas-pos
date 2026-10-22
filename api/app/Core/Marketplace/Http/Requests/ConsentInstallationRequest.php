<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/**
 * MKT-02: consent again to an installed app's listed version (more scopes
 * or webhook events), or change the role it acts as
 * (`core.marketplace.install`; Grants checks the role and scope).
 */
class ConsentInstallationRequest extends MarketplaceRequest
{
    protected string $permission = self::INSTALL;

    public function rules(): array
    {
        return [
            'role_id' => ['required', 'uuid'],
            'scope_type' => ['required', 'string', Rule::in(Scope::TYPES)],
            'scope_id' => ['nullable', 'required_unless:scope_type,'.Scope::TENANT, 'uuid'],
        ];
    }
}
