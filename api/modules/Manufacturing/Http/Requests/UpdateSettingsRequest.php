<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/**
 * PUT manufacturing/companies/{company}/settings (MFG-29):
 * `manufacturing.settings.edit` at the company (403);
 * `{labour_rate_minor, overhead_rate_minor, release_approval}` (rates per
 * labour hour in minor units of the company's base currency, digit
 * strings; configured by the company, not statutory).
 */
class UpdateSettingsRequest extends SettingsRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return app(ScopeResolver::class)->can($this->user(), 'manufacturing.settings.edit', Scope::company($this->companyId()));
    }

    public function rules(): array
    {
        return [
            'labour_rate_minor' => ['required', 'string', 'regex:/^\d{1,15}$/'],
            'overhead_rate_minor' => ['required', 'string', 'regex:/^\d{1,15}$/'],
            'release_approval' => ['required', 'boolean'],
        ];
    }
}
