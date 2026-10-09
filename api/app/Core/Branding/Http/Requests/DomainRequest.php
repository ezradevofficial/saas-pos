<?php

namespace App\Core\Branding\Http\Requests;

use App\Core\Branding\Models\TenantDomain;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BR-05, BR-06: custom domains and the branding settings (subdomain,
 * email sender, SMS sender ID) are tenant-wide: `core.domain.manage` at
 * tenant scope (Owner, Admin). A domain of another tenant is not found
 * (row-level security on route binding).
 */
class DomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('core.domain.manage', Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [];
    }

    public function domain(): TenantDomain
    {
        return $this->route('tenant_domain');
    }
}
