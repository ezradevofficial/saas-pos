<?php

namespace App\Core\MasterData\Taxes\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\Http\Requests\ListTaxCategoriesRequest;
use App\Core\MasterData\Taxes\Http\Requests\StoreTaxCategoryRequest;
use App\Core\MasterData\Taxes\Http\Requests\TaxCategoryActionRequest;
use App\Core\MasterData\Taxes\Http\Requests\TaxCategoryRequest;
use App\Core\MasterData\Taxes\Http\Requests\TaxCategoryRules;
use App\Core\MasterData\Taxes\Http\Requests\UpdateTaxCategoryRequest;
use App\Core\MasterData\Taxes\Http\Resources\TaxCategoryResource;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MD-03: tax categories, shared or per company as items are (TEN-08), with a default tax
 * code per company. Default code changes are audited on the category as
 * `core.tax_category.codes_update` (before and after, by company).
 */
class TaxCategoryController
{
    private const PERMISSIONS = ['core.tax.view', 'core.tax.edit'];

    public function __construct(
        private readonly CompanyReach $reach,
        private readonly Auditor $auditor,
        private readonly MasterDataSharing $sharing,
    ) {}

    public function index(ListTaxCategoriesRequest $request): AnonymousResourceCollection
    {
        $companies = $this->reach->companyIds($request->user(), self::PERMISSIONS);
        $query = TaxCategory::query();

        if ($companies !== null) {
            $query->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        $page = $request->applyStatus($query)->with('codes.taxCode:id,code')->orderBy('name')->orderBy('id')
            ->paginate($request->perPage())->withQueryString();
        $page->getCollection()->each(fn (TaxCategory $category) => $this->onlyReachedCodes($category, $companies));

        return TaxCategoryResource::collection($page);
    }

    public function store(StoreTaxCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $category = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data) {
            // TEN-08: the items mode holds until commit; re-checked under the lock.
            $this->sharing->lockForWrite([TaxCategoryRules::DATA_TYPE]);

            if ($this->sharing->isShared(TaxCategoryRules::DATA_TYPE) !== (($data['company_id'] ?? null) === null)) {
                throw ValidationException::withMessages(['company_id' => __('core.master_data.sharing_changed')]);
            }

            $category = TaxCategory::create(['company_id' => $data['company_id'] ?? null, 'name' => $data['name']]);
            $this->syncCodes($category, $data['codes'] ?? []);

            return $category;
        });

        return $this->respond($request, $category, 201);
    }

    public function show(TaxCategoryRequest $request, TaxCategory $taxCategory): JsonResponse
    {
        return $this->respond($request, $taxCategory);
    }

    public function update(UpdateTaxCategoryRequest $request, TaxCategory $taxCategory): JsonResponse
    {
        $data = $request->validated();

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($taxCategory, $data) {
            TaxCategory::query()->whereKey($taxCategory->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('name', $data)) {
                $taxCategory->fill(['name' => $data['name']])->save();
            }

            $this->syncCodes($taxCategory, $data['codes'] ?? []);
        });

        return $this->respond($request, $taxCategory);
    }

    public function archive(TaxCategoryActionRequest $request, TaxCategory $taxCategory): JsonResponse
    {
        if (! $taxCategory->isArchived()) {
            $taxCategory->archive();
        }

        return $this->respond($request, $taxCategory);
    }

    public function restore(TaxCategoryActionRequest $request, TaxCategory $taxCategory): JsonResponse
    {
        if ($taxCategory->isArchived()) {
            $taxCategory->restore();
        }

        return $this->respond($request, $taxCategory);
    }

    /**
     * Set or clear the default code of each company listed; others keep
     * theirs. One audit entry for the change.
     *
     * @param  list<array{company_id: string, tax_code_id: ?string}>  $codes
     */
    private function syncCodes(TaxCategory $category, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        $current = TaxCategoryCode::query()->where('tax_category_id', $category->id)->get()->keyBy('company_id');
        $before = $current->map(fn (TaxCategoryCode $code) => $code->tax_code_id)->sortKeys()->all();

        foreach ($codes as $entry) {
            $existing = $current->get($entry['company_id']);

            if ($entry['tax_code_id'] === null) {
                $existing?->delete();
                $current->forget($entry['company_id']);

                continue;
            }

            if ($existing !== null) {
                $existing->update(['tax_code_id' => $entry['tax_code_id']]);

                continue;
            }

            $current->put($entry['company_id'], TaxCategoryCode::create([
                'tax_category_id' => $category->id,
                'company_id' => $entry['company_id'],
                'tax_code_id' => $entry['tax_code_id'],
            ]));
        }

        $after = $current->map(fn (TaxCategoryCode $code) => $code->tax_code_id)->sortKeys()->all();

        if ($after !== $before) {
            $this->auditor->record('core.tax_category.codes_update', $category, ['codes' => $before], ['codes' => $after]);
        }
    }

    /** @param list<string>|null $companies */
    private function onlyReachedCodes(TaxCategory $category, ?array $companies): void
    {
        if ($companies !== null) {
            $category->setRelation('codes', $category->codes->filter(
                fn (TaxCategoryCode $code) => in_array($code->company_id, $companies, true),
            )->values());
        }
    }

    private function respond(Request $request, TaxCategory $category, int $status = 200): JsonResponse
    {
        $category->load('codes.taxCode:id,code');
        $this->onlyReachedCodes($category, $this->reach->companyIds($request->user(), self::PERMISSIONS));

        return TaxCategoryResource::make($category)->response()->setStatusCode($status);
    }
}
