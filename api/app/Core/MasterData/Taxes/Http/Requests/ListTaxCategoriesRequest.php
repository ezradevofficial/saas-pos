<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Taxes\Http\Lists\TaxCategoryList;
use App\Core\Tenancy\Models\Company;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * MD-03: tax categories the user reaches: shared ones, and those of the
 * companies where they hold `core.tax.view|edit`; `?company=` (shared
 * categories plus that company's; one the user reaches, else 422), `?status`, `?per_page`,
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
            // A company the user reaches; any other (another tenant's, one out of reach, none) gets the same answer.
            'company' => ['sometimes', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! $this->reaches($value)) {
                    $fail(__('core.tax.company_unreached'));
                }
            }],
        ];
    }

    /** `?company=`: the company whose categories (and the shared ones) to list, once validated. */
    public function company(): ?string
    {
        return $this->validated('company');
    }

    private function reaches(mixed $company): bool
    {
        if (! is_string($company) || ! Str::isUuid($company)) {
            return false;
        }

        $companies = $this->companies();

        // Tenant-wide readers reach every company of the tenant (row-level security keeps it to theirs).
        return $companies === null ? Company::query()->whereKey($company)->exists() : in_array($company, $companies, true);
    }
}
