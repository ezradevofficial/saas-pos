<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-03: one tax category. A shared one is seen by holders of
 * `core.tax.view|edit` anywhere and changed with `core.tax.edit` at tenant
 * scope; a company's one at that company. Not seen: 404 (RBAC-04).
 */
class TaxCategoryRequest extends FormRequest
{
    protected bool $edits = false;

    public function authorize(): bool
    {
        $category = $this->category();
        $reach = app(CompanyReach::class);
        $permissions = ['core.tax.view', 'core.tax.edit'];

        $sees = $category->isShared()
            ? $reach->anywhere($this->user(), $permissions)
            : $reach->reaches($this->user(), Company::query()->findOrFail($category->company_id), $permissions);

        abort_unless($sees, 404);

        return ! $this->edits || $this->user()->can('core.tax.edit', $category);
    }

    public function rules(): array
    {
        return [];
    }

    protected function category(): TaxCategory
    {
        return $this->route('tax_category');
    }
}
