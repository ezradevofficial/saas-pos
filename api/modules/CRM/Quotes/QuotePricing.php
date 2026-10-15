<?php

namespace Modules\CRM\Quotes;

use App\Core\Currency\Money;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\MasterData\Taxes\ItemTaxStatus;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRateMissing;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\CRM\Support\CrmAccess;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Limits;

/**
 * CRM-18 to CRM-20: the lines and totals of a quote, in minor units of
 * its currency (ADR 003, never floats).
 *
 * Per line:
 * - the item (shared or of the company, active) and a unit it has (its
 *   base unit by default), or a description;
 * - the list price from the quote's price list for that unit and
 *   quantity (PriceResolver: breaks, unit factors), on the quote date;
 * - the unit price: the list price, or a typed one; a typed price other
 *   than the list price needs `crm.quote.price` at the branch
 *   (`price_override_not_allowed`); no list price needs a typed price
 *   (`price_required`);
 * - gross = round_half_up(qty × unit price); discount =
 *   round_half_up(gross × percent ÷ 100) with `crm.quote.discount` and
 *   within the user's `max_discount_percent` (RBAC-06,
 *   `discount_not_allowed`, `discount_over_limit`); amount = gross −
 *   discount;
 * - the tax code: the company's code for the item's tax category
 *   (ItemTaxStatus; an item without one is refused with the reason), or
 *   the typed code of a description line (required); tax on the amount by
 *   the core calculator on the quote date in the company's time zone,
 *   inclusive or exclusive. A code without a confirmed rate: strict
 *   refuses (`tax_rate_missing`); otherwise the line has no tax and is
 *   marked `rate_needed`. Rates are never invented.
 *
 * Totals: subtotal = Σ gross, discount = Σ discount, tax = Σ tax,
 * total = Σ line totals (net + tax; the amount itself when inclusive).
 *
 * The permission checks run for the person saving ($actor); null skips
 * them (submission, conversion: the saved lines were checked).
 */
class QuotePricing
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $tax,
        private readonly ItemTaxStatus $taxStatus,
        private readonly CrmAccess $access,
        private readonly Limits $limits,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return array{lines: list<array<string, mixed>>, totals: array{subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int}, problems: list<array{line: int, field: string, code: string, message: string}>, max_discount_percent: string}
     *
     * @throws ApiException the first problem, unless $collect
     */
    public function price(Company $company, string $branchId, string $currency, ?PriceList $list, bool $inclusive, CarbonImmutable $date, array $inputs, ?User $actor, bool $strict, bool $collect = false): array
    {
        $zone = $company->timezone ?: 'UTC';
        $at = CarbonImmutable::parse($date->toDateString(), $zone)->setTime(12, 0);
        $statuses = $this->taxStatus->forCompany($company, $at);
        $lines = [];
        $problems = [];
        $maxDiscount = BigDecimal::zero();
        $totals = ['subtotal_minor' => 0, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0];
        $mayPrice = $actor === null || $this->access->canAt($actor, 'crm.quote.price', $branchId);
        $mayDiscount = $actor === null || $this->access->canAt($actor, 'crm.quote.discount', $branchId);

        foreach (array_values($inputs) as $index => $input) {
            $problem = function (string $field, string $code, array $replace = []) use ($index, &$problems, $collect) {
                $error = CrmError::make($code, $replace, "lines.{$index}.{$field}");

                if (! $collect) {
                    throw $error;
                }

                $problems[] = ['line' => $index, 'field' => $field, 'code' => $code, 'message' => $error->getMessage()];
            };

            try {
                $line = $this->line($company, $currency, $list, $inclusive, $date, $at, $statuses, $input, $index, $strict, $problem, $mayPrice, $mayDiscount);
            } catch (TaxRateMissing $e) {
                if (! $collect) {
                    throw $e;
                }

                $problems[] = ['line' => $index, 'field' => 'tax_code_id', 'code' => 'tax_rate_missing', 'message' => $e->getMessage()];

                continue;
            }

            if ($line === null) {
                continue;
            }

            $percent = BigDecimal::of($line['discount_percent']);

            if ($percent->isPositive() && $actor !== null && $mayDiscount && ! $this->limits->withinDiscount($actor, $branchId, $percent)) {
                $max = $this->limits->maxDiscount($actor, $branchId);
                $problem('discount_percent', $max === null ? 'discount_not_allowed' : 'discount_over_limit', ['limit' => $max === null ? '0' : (string) BigDecimal::of($max)->strippedOfTrailingZeros()]);
            }

            $maxDiscount = $percent->isGreaterThan($maxDiscount) ? $percent : $maxDiscount;
            $lines[] = $line;

            foreach (['subtotal_minor' => 'gross_minor', 'discount_minor' => 'discount_minor', 'tax_minor' => 'tax_minor', 'total_minor' => 'total_minor'] as $total => $key) {
                $totals[$total] += $line[$key];
            }
        }

        return ['lines' => $lines, 'totals' => $totals, 'problems' => $problems, 'max_discount_percent' => (string) $maxDiscount->toScale(2)];
    }

    /**
     * @param  array<string, array{sellable: bool, reason: ?string, tax_code_id: string}>  $statuses
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null null when the line could not be read (problems collected)
     */
    private function line(Company $company, string $currency, ?PriceList $list, bool $inclusive, CarbonImmutable $date, CarbonImmutable $at, array $statuses,
        array $input, int $index, bool $strict, callable $problem, bool $mayPrice, bool $mayDiscount): ?array
    {
        $item = null;

        if (! empty($input['item_id'])) {
            $item = Str::isUuid((string) $input['item_id']) ? Item::query()->whereKey($input['item_id'])->whereNull('archived_at')
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->first() : null;

            if ($item === null) {
                $problem('item_id', 'item_unknown');

                return null;
            }
        }

        $uomId = $input['uom_id'] ?? $item?->base_uom_id;

        if ($item !== null && $uomId !== $item->base_uom_id && ! ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->exists()) {
            $problem('uom_id', 'uom_not_on_item');

            return null;
        }

        $description = trim((string) ($input['description'] ?? ''));

        if ($description === '' && $item === null) {
            $problem('description', 'description_required');

            return null;
        }

        $qty = BigDecimal::of((string) $input['qty']);

        if (! $qty->isPositive()) {
            $problem('qty', 'qty_invalid');

            return null;
        }

        $listPrice = null;

        if ($item !== null && $list !== null) {
            $listPrice = $this->prices->priceFor($item, (string) $uomId, $list, $date->toDateString(), (string) $qty)?->money->minor();
        }

        $typed = isset($input['unit_price_minor']) && $input['unit_price_minor'] !== '' ? (string) $input['unit_price_minor'] : null;

        if ($typed === null && $listPrice === null) {
            $problem('unit_price_minor', 'price_required');

            return null;
        }

        if ($typed !== null && $listPrice !== null && BigDecimal::of($typed)->compareTo($listPrice) !== 0 && ! $mayPrice) {
            $problem('unit_price_minor', 'price_override_not_allowed');
        }

        $unit = (int) ($typed ?? $listPrice);
        $percent = BigDecimal::of((string) ($input['discount_percent'] ?? '0'))->toScale(2, RoundingMode::Unnecessary);

        if ($percent->isPositive() && ! $mayDiscount) {
            $problem('discount_percent', 'discount_not_allowed', ['limit' => '0']);
        }

        // The tax code: from core for an item (never typed), the company's typed code for a description line.
        $code = null;

        if ($item !== null) {
            $status = ItemTaxStatus::of($item->tax_category_id, $statuses);

            if ($status['tax_code_id'] === null || $status['reason'] === ItemTaxStatus::TAX_CODE_ARCHIVED) {
                $problem('item_id', $status['reason'] ?? ItemTaxStatus::TAX_CODE_MISSING, ['item' => $item->name]);

                return null;
            }

            $code = TaxCode::query()->find($status['tax_code_id']);
        } elseif (! empty($input['tax_code_id'])) {
            $code = Str::isUuid((string) $input['tax_code_id'])
                ? TaxCode::query()->where('company_id', $company->id)->whereKey($input['tax_code_id'])->whereNull('archived_at')->first()
                : null;

            if ($code === null) {
                $problem('tax_code_id', 'tax_code_unknown');

                return null;
            }
        } else {
            $problem('tax_code_id', 'tax_code_required');

            return null;
        }

        $gross = BigDecimal::of($unit)->multipliedBy($qty)->toScale(0, RoundingMode::HalfUp);
        $discount = $gross->multipliedBy($percent)->dividedBy(100, 0, RoundingMode::HalfUp);
        $amount = Money::ofMinor((string) $gross->minus($discount), $currency);
        $rateNeeded = false;

        try {
            $result = $this->tax->forLine($amount, [$code], $inclusive, $at);
            $net = (int) $result->net->minor();
            $tax = (int) $result->totalTax()->minor();
            $total = (int) $result->gross->minor();
            $rate = $result->tax[0]->rate ?? null;
        } catch (TaxRateMissing $e) {
            if ($strict) {
                throw $e;
            }

            $rateNeeded = true;
            $net = (int) $amount->minor();
            $tax = 0;
            $total = (int) $amount->minor();
            $rate = null;
        }

        return [
            'line_no' => $index + 1,
            'item_id' => $item?->id,
            'description' => mb_substr($description !== '' ? $description : (string) $item?->name, 0, 500),
            'uom_id' => $uomId,
            'qty' => (string) $qty->toScale(6, RoundingMode::Unnecessary),
            'list_price_minor' => $listPrice === null ? null : (int) $listPrice,
            'unit_price_minor' => $unit,
            'discount_percent' => (string) $percent,
            'gross_minor' => (int) (string) $gross,
            'discount_minor' => (int) (string) $discount,
            'net_minor' => $net,
            'tax_minor' => $tax,
            'total_minor' => $total,
            'tax_code_id' => $code->id,
            'tax_rate' => $rate,
            'rate_needed' => $rateNeeded,
        ];
    }

    /** CRM-17: the quote's price list: the party's in that currency and company, else the company's default in it. */
    public function listFor(Company $company, string $currency, ?string $partyListId): ?PriceList
    {
        if ($partyListId !== null) {
            $list = PriceList::query()->whereKey($partyListId)->where('company_id', $company->id)->where('currency', $currency)->whereNull('archived_at')->first();

            if ($list !== null) {
                return $list;
            }
        }

        return $this->prices->defaultList($company, $currency);
    }
}
