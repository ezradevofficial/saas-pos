<?php

namespace Modules\FixedAssets\Categories;

use App\Core\Tenancy\Models\Company;
use Modules\FixedAssets\Depreciation\AssetBook;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Models\Settings;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\TaxBook\TaxClasses;

/**
 * FA-01, FA-02: categories with their default depreciation terms, and the
 * company settings. A category's terms must fit its method (`method_terms`):
 * straight line and declining balance need a life, declining balance a
 * rate; a tax class must be one of the company's country pack.
 */
class Categories
{
    public function __construct(private readonly TaxClasses $taxClasses) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, array $data): Category
    {
        $data = $this->terms($company, $data, null);

        if (Category::query()->where('company_id', $company->id)->where('code', $data['code'])->exists()) {
            throw FaError::make('code_taken', field: 'code');
        }

        return Category::query()->create([...$data, 'company_id' => $company->id]);
    }

    /** @param array<string, mixed> $data */
    public function update(Category $category, array $data): Category
    {
        $company = Company::query()->findOrFail($category->company_id);
        $data = $this->terms($company, $data, $category);

        if (isset($data['code']) && Category::query()->where('company_id', $company->id)->where('code', $data['code'])->whereKeyNot($category->id)->exists()) {
            throw FaError::make('code_taken', field: 'code');
        }

        $category->fill($data)->save();

        return $category->refresh();
    }

    public function archive(Category $category, bool $archive): Category
    {
        $category->fill(['archived_at' => $archive ? now() : null])->save();

        return $category->refresh();
    }

    /** The settings of $company (the defaults until saved). */
    public static function settings(string $companyId): Settings
    {
        return Settings::query()->where('company_id', $companyId)->first()
            ?? new Settings(['company_id' => $companyId, 'convention' => 'days', 'tax_book' => false]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function terms(Company $company, array $data, ?Category $current): array
    {
        $method = $data['method'] ?? $current?->method;
        $life = array_key_exists('useful_life_months', $data) ? $data['useful_life_months'] : $current?->useful_life_months;
        $rate = array_key_exists('declining_rate', $data) ? $data['declining_rate'] : $current?->declining_rate;

        if (in_array($method, [AssetBook::STRAIGHT_LINE, AssetBook::DECLINING_BALANCE], true) && $life === null) {
            throw FaError::make('method_terms', field: 'useful_life_months');
        }

        if ($method === AssetBook::DECLINING_BALANCE && $rate === null) {
            throw FaError::make('method_terms', field: 'declining_rate');
        }

        if (($data['tax_class'] ?? null) !== null && $this->taxClasses->find((string) $company->country, (string) $data['tax_class']) === null) {
            throw FaError::make('tax_class_unknown', field: 'tax_class');
        }

        return $data;
    }
}
