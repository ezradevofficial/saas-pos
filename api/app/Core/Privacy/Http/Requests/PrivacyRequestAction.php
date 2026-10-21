<?php

namespace App\Core\Privacy\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * NFR-08: data subject requests are tenant-wide: each action needs its
 * permission at tenant scope (RBAC-04). Subclasses name the permission.
 */
class PrivacyRequestAction extends FormRequest
{
    protected string $permission = 'core.privacy_request.view';

    public function authorize(): bool
    {
        return $this->user()?->can($this->permission, Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
