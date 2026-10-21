<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MKT-01, MKT-02: marketplace routes are tenant-wide. Browsing and the
 * installed apps take `core.marketplace.view`; installing, consenting and
 * uninstalling `core.marketplace.install`; the developer portal
 * `core.developer.manage` (all at tenant scope; Owner and Admin
 * templates). None of them is public: API keys and app tokens never reach
 * them (ADR 020).
 */
abstract class MarketplaceRequest extends FormRequest
{
    public const VIEW = 'core.marketplace.view';

    public const INSTALL = 'core.marketplace.install';

    public const DEVELOP = 'core.developer.manage';

    protected string $permission = self::VIEW;

    public function authorize(): bool
    {
        return $this->user()?->can($this->permission, Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
