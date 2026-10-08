<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-02, AUTH-09, L10N-01: the tenant's settings are tenant-wide, so
 * reading and changing them both take `core.settings.edit` at tenant scope.
 */
class TenantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('core.settings.edit', Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
