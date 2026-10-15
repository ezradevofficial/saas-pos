<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\FixedAssets\Categories\Categories;
use Modules\FixedAssets\Http\Requests\CategoryActionRequest;
use Modules\FixedAssets\Http\Requests\ItemCategoryActionRequest;
use Modules\FixedAssets\Http\Requests\ListCategoriesRequest;
use Modules\FixedAssets\Http\Requests\ListItemCategoriesRequest;
use Modules\FixedAssets\Http\Requests\ShowSettingsRequest;
use Modules\FixedAssets\Http\Requests\StoreCategoryRequest;
use Modules\FixedAssets\Http\Requests\StoreItemCategoryRequest;
use Modules\FixedAssets\Http\Requests\TaxBookRequest;
use Modules\FixedAssets\Http\Requests\UpdateCategoryRequest;
use Modules\FixedAssets\Http\Requests\UpdateSettingsRequest;
use Modules\FixedAssets\Http\Resources\RecordResources;
use Modules\FixedAssets\Http\Resources\Values;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Models\DepreciationRun;
use Modules\FixedAssets\Models\ItemCategory;
use Modules\FixedAssets\Models\Settings;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\TaxBook\TaxBook;
use Modules\FixedAssets\TaxBook\TaxClasses;

/** FA-01, FA-02, FA-20, FA-21: a company's settings, categories, items flagged as assets and the tax book. */
class SetupController
{
    public function __construct(
        private readonly Categories $categories,
        private readonly TaxClasses $taxClasses,
        private readonly TaxBook $taxBook,
    ) {}

    public function settings(ShowSettingsRequest $request, Company $company): JsonResponse
    {
        return response()->json(['data' => $this->settingsData($company)]);
    }

    public function updateSettings(UpdateSettingsRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        FaError::transaction(function () use ($company, $data) {
            $settings = Settings::query()->where('company_id', $company->id)->lockForUpdate()->first() ?? new Settings(['company_id' => $company->id]);

            if (isset($data['convention']) && $data['convention'] !== ($settings->convention ?? 'days')
                && DepreciationRun::query()->where('company_id', $company->id)->where('status', 'posted')->exists()) {
                throw FaError::make('convention_locked', field: 'convention');
            }

            if (($data['tax_book'] ?? false) === true) {
                $this->taxBook->assertConfirmed($company);
            }

            $settings->fill($data)->save();
        });

        return response()->json(['data' => $this->settingsData($company)]);
    }

    public function categories(ListCategoriesRequest $request): AnonymousResourceCollection
    {
        $query = Category::query()->with('company');
        $request->applyTouchedCompanies($query);
        $status = $request->validated('status');

        match ($status) {
            'archived' => $query->whereNotNull('archived_at'),
            'all' => $query,
            default => $query->whereNull('archived_at'),
        };

        if ($request->filled('search')) {
            $like = '%'.addcslashes((string) $request->validated('search'), '\\%_').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like));
        }

        return AssetController::page($query->orderBy('code')->orderBy('id')->paginate($request->perPage())->withQueryString(), fn (Category $c) => RecordResources::category($c));
    }

    public function storeCategory(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $category = $this->categories->create($request->company(), array_diff_key($data, ['company_id' => true]));

        return response()->json(['data' => RecordResources::category($category->load('company'))], 201);
    }

    public function updateCategory(UpdateCategoryRequest $request, Category $faCategory): JsonResponse
    {
        return response()->json(['data' => RecordResources::category($this->categories->update($faCategory, $request->validated())->load('company'))]);
    }

    public function archiveCategory(CategoryActionRequest $request, Category $faCategory): JsonResponse
    {
        return response()->json(['data' => RecordResources::category($this->categories->archive($faCategory, true)->load('company'))]);
    }

    public function restoreCategory(CategoryActionRequest $request, Category $faCategory): JsonResponse
    {
        return response()->json(['data' => RecordResources::category($this->categories->archive($faCategory, false)->load('company'))]);
    }

    public function itemCategories(ListItemCategoriesRequest $request): AnonymousResourceCollection
    {
        $query = ItemCategory::query()->with(['company', 'item', 'category'])->whereNull('archived_at');
        $request->applyTouchedCompanies($query);

        return AssetController::page($query->orderBy('created_at')->orderBy('id')->paginate($request->perPage())->withQueryString(), fn (ItemCategory $row) => RecordResources::itemCategory($row));
    }

    public function storeItemCategory(StoreItemCategoryRequest $request): JsonResponse
    {
        $company = $request->company();
        $item = Item::query()->findOrFail($request->validated('item_id'));
        $category = Category::query()->findOrFail($request->validated('category_id'));

        if ($item->company_id !== null && $item->company_id !== $company->id) {
            throw FaError::make('item_other_company', field: 'item_id');
        }

        if ($item->type === 'stock') {
            throw FaError::make('item_is_stock', field: 'item_id');
        }

        if ($category->company_id !== $company->id || $category->archived_at !== null) {
            throw FaError::make('category_other_company', field: 'category_id');
        }

        if (ItemCategory::query()->where('company_id', $company->id)->where('item_id', $item->id)->whereNull('archived_at')->exists()) {
            throw FaError::make('item_flagged', field: 'item_id');
        }

        $row = ItemCategory::query()->create(['company_id' => $company->id, 'item_id' => $item->id, 'category_id' => $category->id, 'created_by' => $request->user()->id]);

        return response()->json(['data' => RecordResources::itemCategory($row->load(['company', 'item', 'category']))], 201);
    }

    public function archiveItemCategory(ItemCategoryActionRequest $request, ItemCategory $faItemCategory): JsonResponse
    {
        $faItemCategory->fill(['archived_at' => now()])->save();

        return response()->json(['data' => RecordResources::itemCategory($faItemCategory->load(['company', 'item', 'category']))]);
    }

    /**
     * FA-20: the tax book of a year (this year by default). While its rates
     * are unconfirmed nothing is computed: the answer says so
     * (`status: unconfirmed`, the categories and classes in question).
     */
    public function taxBook(TaxBookRequest $request, Company $company): JsonResponse
    {
        $unconfirmed = $this->taxBook->unconfirmed($company);

        if ($unconfirmed !== [] || Category::query()->where('company_id', $company->id)->whereNull('archived_at')->doesntExist()) {
            return response()->json(['status' => 'unconfirmed', 'code' => 'tax_rates_unconfirmed', 'unconfirmed' => $unconfirmed,
                'message' => __('fixed_assets.errors.tax_rates_unconfirmed', ['classes' => implode(', ', array_map(fn (array $row) => $row['tax_class'] ?? $row['category'], $unconfirmed)) ?: '-']),
                'data' => []]);
        }

        $rows = $this->taxBook->year($company, (int) $request->validated('year', now()->year));
        $values = Values::atCompany($request, $company->id);
        $money = fn (int $minor) => Values::money($values, $minor, (string) $company->base_currency);

        return response()->json(['status' => 'confirmed', 'data' => array_map(fn (array $row) => [
            ...$row, 'opening' => $money($row['opening']), 'allowance' => $money($row['allowance']), 'closing' => $money($row['closing']),
        ], $rows)]);
    }

    private function settingsData(Company $company): array
    {
        $settings = Categories::settings($company->id);

        return [
            'company_id' => $company->id,
            'convention' => (string) $settings->convention,
            'tax_book' => (bool) $settings->tax_book,
            'convention_locked' => DepreciationRun::query()->where('company_id', $company->id)->where('status', 'posted')->exists(),
            'base_currency' => (string) $company->base_currency,
            // FA-20: the country pack's classes (unconfirmed ones are listed with what the owner must confirm).
            'tax_classes' => $this->taxClasses->for((string) $company->country),
            'tax_todo' => $this->taxClasses->todo((string) $company->country),
            'tax_unconfirmed' => $this->taxBook->unconfirmed($company),
        ];
    }
}
