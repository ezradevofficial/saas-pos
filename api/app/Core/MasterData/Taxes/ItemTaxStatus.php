<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Tenancy\Models\Company;
use Carbon\CarbonInterface;

/**
 * Whether items of each tax category may be sold by $company now (MD-03,
 * CP-02, ADR 007: rates are never invented). An item is sellable only when
 * its tax is known: it has a tax category, the category has a default tax
 * code for the company, the code is not archived, and (unless exempt or
 * withholding) the code has a confirmed rate on the company's local date.
 * Otherwise the reason says what is missing:
 *
 *  - `tax_category_missing`: the item has no tax category
 *  - `tax_code_missing`: the category has no tax code for the company
 *  - `tax_code_archived`: the category's code was archived
 *  - `tax_rate_needed`: no rate in force, or one still to be confirmed
 *    ("Rate needed")
 *
 * The POS refuses such items at the till and in the API. Three queries for
 * every category of the company, whatever the number of items.
 */
class ItemTaxStatus
{
    public const TAX_CATEGORY_MISSING = 'tax_category_missing';

    public const TAX_CODE_MISSING = 'tax_code_missing';

    public const TAX_CODE_ARCHIVED = 'tax_code_archived';

    public const TAX_RATE_NEEDED = 'tax_rate_needed';

    /**
     * Status by tax category id; a category without an entry has no code
     * for the company (use of()).
     *
     * @return array<string, array{sellable: bool, reason: ?string, tax_code_id: string}>
     */
    public function forCompany(Company $company, CarbonInterface $at): array
    {
        $links = TaxCategoryCode::query()->where('company_id', $company->id)->pluck('tax_code_id', 'tax_category_id')->all();

        if ($links === []) {
            return [];
        }

        $codes = TaxCode::query()->whereIn('id', array_unique(array_values($links)))->with('rates')->get()->keyBy('id');
        $codes->each(fn (TaxCode $code) => $code->setRelation('company', $company));
        $statuses = [];

        foreach ($links as $categoryId => $codeId) {
            $code = $codes->get($codeId);
            $statuses[$categoryId] = ['sellable' => true, 'reason' => null, 'tax_code_id' => $codeId];

            $reason = match (true) {
                $code === null => self::TAX_CODE_MISSING,
                $code->isArchived() => self::TAX_CODE_ARCHIVED,
                $code->isExempt(), $code->kind === 'withholding' => null,
                default => ($rate = $code->rateOn($at)) === null || $rate->isNeeded() ? self::TAX_RATE_NEEDED : null,
            };

            if ($reason !== null) {
                $statuses[$categoryId] = ['sellable' => false, 'reason' => $reason, 'tax_code_id' => $codeId];
            }
        }

        return $statuses;
    }

    /**
     * An item's status from forCompany()'s result.
     *
     * @param  array<string, array{sellable: bool, reason: ?string, tax_code_id: string}>  $statuses
     * @return array{sellable: bool, reason: ?string, tax_code_id: ?string}
     */
    public static function of(?string $taxCategoryId, array $statuses): array
    {
        return match (true) {
            $taxCategoryId === null => ['sellable' => false, 'reason' => self::TAX_CATEGORY_MISSING, 'tax_code_id' => null],
            isset($statuses[$taxCategoryId]) => $statuses[$taxCategoryId],
            default => ['sellable' => false, 'reason' => self::TAX_CODE_MISSING, 'tax_code_id' => null],
        };
    }
}
