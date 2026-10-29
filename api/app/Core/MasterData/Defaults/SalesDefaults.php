<?php

namespace App\Core\MasterData\Defaults;

use App\Core\CountryPacks\PackDocuments;
use App\Core\CountryPacks\PackFile;
use App\Core\CountryPacks\PackLabels;
use App\Core\Localisation\TenantLocale;
use App\Core\MasterData\Items\ItemSharing;
use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\ApplyCountryPack;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Company;

/**
 * ONB-03, MD-03, CP-01: what a company needs before it can sell. A default
 * price list in its currency (prices include tax), tax categories from its
 * country pack (`tax_categories`, each mapped to the company's copy of the
 * pack tax code), and payment methods with cash at least.
 *
 * Adds only what is missing. A company that has any price list (archived
 * included) or any tax category of its own keeps what it has; a shared
 * category is only given this company's default code when a pack category
 * of the same name has none for it. No rate lives here: rates stay on the
 * tax codes copied from the pack.
 */
class SalesDefaults implements ModuleDefault
{
    public function __construct(
        private readonly ApplyCountryPack $packs,
        private readonly PackDocuments $documents,
        private readonly TenantLocale $locale,
        private readonly MasterDataSharing $sharing,
        private readonly DefaultPaymentMethods $payments,
    ) {}

    public function seed(Company $company): int
    {
        Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

        $this->packs->apply($company);

        return $this->priceList($company) + $this->taxCategories($company) + $this->paymentMethods($company);
    }

    private function priceList(Company $company): int
    {
        if (PriceList::query()->where('company_id', $company->id)->exists()) {
            return 0;
        }

        PriceList::create([
            'company_id' => $company->id,
            'name' => __('core.defaults.price_list', [], $this->locale->current()),
            'currency' => $company->base_currency,
            'tax_inclusive' => true,
            'is_default' => true,
        ]);

        return 1;
    }

    private function taxCategories(Company $company): int
    {
        $content = $this->documents->current($company->country, 'tax')['content'] ?? null;
        $wanted = $content === null ? [] : PackFile::fromArray($content)->taxCategories();

        if ($wanted === [] || TaxCategory::query()->where('company_id', $company->id)->exists()) {
            return 0;
        }

        $shared = $this->sharing->isShared(ItemSharing::DATA_TYPE);
        $locale = $this->locale->current();
        // Shared categories the tenant made are theirs: none is added beside them.
        $sharedExist = $shared && TaxCategory::query()->whereNull('company_id')->exists();
        $created = 0;

        foreach ($wanted as $row) {
            $code = TaxCode::query()->where('company_id', $company->id)->where('pack_code', $row['tax_code'])->whereNull('archived_at')->first();

            if ($code === null) {
                continue;
            }

            $name = PackLabels::taxCategory($company->country, $row['key'], $locale);
            $category = TaxCategory::query()->where('name', $name)
                ->when($shared, fn ($query) => $query->whereNull('company_id'), fn ($query) => $query->where('company_id', $company->id))
                ->orderBy('id')->first();

            if ($category === null) {
                if ($sharedExist) {
                    continue;
                }

                $category = TaxCategory::create(['company_id' => $shared ? null : $company->id, 'name' => $name]);
                $created++;
            }

            if (! TaxCategoryCode::query()->where('tax_category_id', $category->id)->where('company_id', $company->id)->exists()) {
                TaxCategoryCode::create(['tax_category_id' => $category->id, 'company_id' => $company->id, 'tax_code_id' => $code->id]);
                $created++;
            }
        }

        return $created;
    }

    private function paymentMethods(Company $company): int
    {
        // The till needs cash: when the company has none at all, the cash it is given is on.
        $hasCash = PaymentMethod::query()->where('company_id', $company->id)->where('type', 'cash')->exists();

        return $this->payments->seed($company, activateCash: ! $hasCash);
    }
}
