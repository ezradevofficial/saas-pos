<?php

namespace App\Core\Onboarding\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ONB-02..ONB-07: onboarding is tenant-wide, so reading it takes
 * `core.onboarding.view` at tenant scope (subclasses: `edit`).
 */
class OnboardingRequest extends FormRequest
{
    protected string $permission = 'core.onboarding.view';

    public function authorize(): bool
    {
        return $this->user()?->can($this->permission, Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
