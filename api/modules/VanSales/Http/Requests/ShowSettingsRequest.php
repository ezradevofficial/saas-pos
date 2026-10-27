<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/** GET van-sales/companies/{company}/settings: any van sales permission at the company (the device reads them through the pull). */
class ShowSettingsRequest extends VanSalesRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'van_sales.van.view', Scope::company((string) $this->route('company')))
            || app(ScopeResolver::class)->can($this->user(), 'van_sales.settings.edit', Scope::company((string) $this->route('company')));
    }
}
