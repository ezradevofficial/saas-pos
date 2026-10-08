<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Audit\Auditor;
use App\Core\MasterData\Sharing\SharedRecords;

/**
 * TEN-08: tax categories follow the items sharing mode (items point to
 * them). Assigning a shared category to one company keeps only that
 * company's default tax code (a company's category maps its own company);
 * the codes removed are audited as `core.tax_category.codes_update`.
 */
class TaxCategorySharedRecords implements SharedRecords
{
    public function __construct(private readonly Auditor $auditor) {}

    public function unassignedCount(): int
    {
        return TaxCategory::query()->whereNull('company_id')->count();
    }

    public function assignTo(string $companyId): int
    {
        $count = 0;

        TaxCategory::query()->whereNull('company_id')->lazyById()->each(function (TaxCategory $category) use ($companyId, &$count) {
            $codes = TaxCategoryCode::query()->where('tax_category_id', $category->id)->get();
            $before = $codes->mapWithKeys(fn (TaxCategoryCode $code) => [$code->company_id => $code->tax_code_id])->sortKeys()->all();
            $codes->where('company_id', '!=', $companyId)->each(fn (TaxCategoryCode $code) => $code->delete());
            $after = array_intersect_key($before, [$companyId => true]);

            $category->company_id = $companyId;
            $category->save();

            if ($after !== $before) {
                $this->auditor->record('core.tax_category.codes_update', $category, ['codes' => $before], ['codes' => $after]);
            }

            $count++;
        });

        return $count;
    }

    public function release(): int
    {
        $count = 0;

        TaxCategory::query()->whereNotNull('company_id')->lazyById()->each(function (TaxCategory $category) use (&$count) {
            $category->company_id = null;
            $category->save();
            $count++;
        });

        return $count;
    }
}
