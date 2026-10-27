<?php

namespace Modules\Hotel\Support;

use App\Core\Currency\Money;
use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Taxes\ItemTaxStatus;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * HOT-32, MD-03: the tax on a hotel charge. The codes are the company's code
 * for the item's tax category plus any levy tax codes the company lists in
 * its settings (country-pack or tenant data, effective-dated rates). Rates
 * are never invented: a code without a confirmed rate posts the line
 * untaxed and flagged `tax_rate_needed` (CP-01, CP-02). Amounts are minor
 * units; tax is rounded half up once per line (ADR 003).
 */
class Taxing
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly ItemTaxStatus $statuses,
    ) {}

    /**
     * @param  list<string>  $levyIds  extra tax code ids (the company's levies)
     * @return array{net: int, tax: int, gross: int, taxes: list<array{tax_code_id: string, code: string, kind: string, rate: ?string, amount_minor: int}>, flags: list<string>}
     */
    public function split(string $companyId, ?string $itemId, array $levyIds, int $amount, string $currency, bool $inclusive, string $businessDate): array
    {
        $company = Company::query()->findOrFail($companyId);
        $at = CarbonImmutable::parse($businessDate.' 12:00:00', CompanyClock::timezone($companyId));
        $flags = [];
        $codes = [];

        if ($itemId === null) {
            $flags[] = 'tax_not_configured';
        } else {
            $item = Item::query()->find($itemId);
            $status = ItemTaxStatus::of($item?->tax_category_id, $this->statuses->forCompany($company, $at));

            if ($status['tax_code_id'] === null) {
                $flags[] = 'tax_code_missing';
            } else {
                $codes[] = $status['tax_code_id'];
            }
        }

        $codes = array_values(array_unique([...$codes, ...$levyIds]));
        $loaded = $codes === [] ? collect() : TaxCode::query()->whereKey($codes)->with('rates')->get()->each(fn (TaxCode $code) => $code->setRelation('company', $company));
        $ordered = array_values(array_filter(array_map(fn (string $id) => $loaded->firstWhere('id', $id), $codes)));

        if ($ordered === []) {
            return $this->untaxed($amount, $flags);
        }

        try {
            $result = $this->calculator->forLine(Money::ofMinor($amount, $currency), $ordered, $inclusive, $at);
        } catch (ApiException $e) {
            if (in_array($e->errorCode, ['tax_rate_missing', 'tax_code_archived'], true)) {
                return $this->untaxed($amount, [...$flags, $e->errorCode === 'tax_rate_missing' ? 'tax_rate_needed' : 'tax_code_archived']);
            }

            throw $e;
        }

        return [
            'net' => (int) $result->net->minor(),
            'tax' => (int) $result->totalTax()->minor(),
            'gross' => (int) $result->gross->minor(),
            'taxes' => array_map(fn ($tax) => [
                'tax_code_id' => $tax->taxCodeId, 'code' => $tax->code, 'kind' => $tax->kind, 'rate' => $tax->rate, 'amount_minor' => (int) $tax->amount->minor(),
            ], $result->tax),
            'flags' => $flags,
        ];
    }

    /** @param list<string> $flags */
    private function untaxed(int $amount, array $flags): array
    {
        return ['net' => $amount, 'tax' => 0, 'gross' => $amount, 'taxes' => [], 'flags' => $flags];
    }
}
