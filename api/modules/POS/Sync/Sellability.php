<?php

namespace Modules\POS\Sync;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * POS-11: whether an item can be sold at a company at an instant, and
 * the tax code that applies: the item's tax category → the company's
 * default code for it. Rates are never guessed: an item without a code
 * (`tax_code_missing`), or whose code has no confirmed rate that day
 * (`rate_needed`, "Rate needed" in the tax settings), is not sellable.
 * The sync data (phase 4 Task 2) exposes `sellable` and `reason` per item
 * from here, so the till blocks it before the API would refuse the sale.
 */
class Sellability
{
    public const RATE_NEEDED = 'rate_needed';

    public const TAX_CODE_MISSING = 'tax_code_missing';

    /** @var array<string, ?TaxCode> */
    private array $codes = [];

    /** The item's tax code at $company (rates and company loaded), or null. */
    public function taxCode(Item $item, Company $company): ?TaxCode
    {
        if ($item->tax_category_id === null) {
            return null;
        }

        $key = "{$item->tax_category_id}:{$company->id}";

        if (! array_key_exists($key, $this->codes)) {
            $id = TaxCategoryCode::query()
                ->where('tax_category_id', $item->tax_category_id)
                ->where('company_id', $company->id)
                ->value('tax_code_id');
            $code = $id === null ? null : TaxCode::query()->with('rates')->find($id);
            $code?->setRelation('company', $company);
            $this->codes[$key] = $code;
        }

        return $this->codes[$key];
    }

    /** @return array{sellable: bool, reason: ?string} */
    public function check(Item $item, Company $company, DateTimeInterface $at): array
    {
        $code = $this->taxCode($item, $company);

        if ($code === null) {
            return ['sellable' => false, 'reason' => self::TAX_CODE_MISSING];
        }

        if ($code->isExempt() || $code->kind === 'withholding') {
            return ['sellable' => true, 'reason' => null];
        }

        $rate = $code->rateOn(CarbonImmutable::instance($at));

        return $rate === null || $rate->isNeeded()
            ? ['sellable' => false, 'reason' => self::RATE_NEEDED]
            : ['sellable' => true, 'reason' => null];
    }
}
