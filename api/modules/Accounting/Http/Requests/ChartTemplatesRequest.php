<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/** GET accounting/chart-templates: the country templates (global reference data), for whoever manages a chart somewhere (ACC-01). */
class ChartTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'accounting.account.manage')
            || app(ScopeResolver::class)->can($this->user(), 'accounting.account.view');
    }

    public function rules(): array
    {
        return [];
    }
}
