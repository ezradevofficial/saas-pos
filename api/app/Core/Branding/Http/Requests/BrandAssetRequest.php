<?php

namespace App\Core\Branding\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BR-02: the tenant's brand assets (logos, favicons, sign-in
 * backgrounds). Anyone who may see or edit a theme somewhere may list
 * them: a company's theme may use any of the tenant's assets.
 */
class BrandAssetRequest extends FormRequest
{
    /** @var list<string> */
    protected array $permissions = ['core.theme.view', 'core.theme.edit'];

    public function authorize(): bool
    {
        $scopes = app(ScopeResolver::class);

        foreach ($this->permissions as $permission) {
            if ($scopes->can($this->user(), $permission)) {
                return true;
            }
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', 'in:logo,favicon,background'],
        ];
    }
}
