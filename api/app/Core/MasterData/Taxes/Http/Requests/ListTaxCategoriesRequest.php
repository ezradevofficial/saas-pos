<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Taxes\Http\Lists\TaxCategoryList;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-03: tax categories the user reaches: shared ones, and those of the
 * companies where they hold `core.tax.view|edit`; `?status`, `?per_page`,
 * `?search=` (name), `?sort` and an export (`?format`, `?columns[]`;
 * TaxCategoryList, EXP-01).
 */
class ListTaxCategoriesRequest extends FormRequest
{
    use ListsRecords;

    public const PERMISSIONS = ['core.tax.view', 'core.tax.edit'];

    private ?TaxCategoryList $definition = null;

    /** @var list<string>|null|false the reached companies, once read (false: not yet) */
    private array|null|false $companies = false;

    public function list(): ListDefinition
    {
        return $this->definition ??= new TaxCategoryList($this->companies());
    }

    /** @return list<string>|null the companies the user reaches (null: every company) */
    public function companies(): ?array
    {
        if ($this->companies === false) {
            $this->companies = app(CompanyReach::class)->companyIds($this->user(), self::PERMISSIONS);
        }

        return $this->companies;
    }

    public function authorize(): bool
    {
        return app(CompanyReach::class)->anywhere($this->user(), self::PERMISSIONS);
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
