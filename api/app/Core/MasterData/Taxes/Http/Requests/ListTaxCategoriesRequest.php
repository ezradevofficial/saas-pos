<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Http\Requests\ListsArchivable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-03: tax categories the user reaches: shared ones, and those of the
 * companies where they hold `core.tax.view|edit`; `?status`, `?per_page`.
 */
class ListTaxCategoriesRequest extends FormRequest
{
    use ListsArchivable;

    public function authorize(): bool
    {
        return app(CompanyReach::class)->anywhere($this->user(), ['core.tax.view', 'core.tax.edit']);
    }

    public function rules(): array
    {
        return $this->listRules();
    }
}
