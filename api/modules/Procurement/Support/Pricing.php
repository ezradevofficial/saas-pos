<?php

namespace Modules\Procurement\Support;

use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRateMissing;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * PRC-13, PRC-15, PRC-16: line amounts and currency conversion.
 *
 * - A line's amount is round_half_up(qty × unit price) in minor units; tax
 *   comes from the core code and the rate in force on the document date in
 *   the company's time zone (TaxCalculator, MD-03), inclusive or exclusive.
 *   Strict: a code without a confirmed rate refuses (`tax_rate_missing`);
 *   otherwise the line is kept without tax and its rate stays null ("Rate
 *   needed"). Rates are never invented.
 * - Conversions use the company's stored rate at an instant (Converter,
 *   CUR-03), frozen as an FxSnapshot (CUR-04); a missing rate refuses
 *   (`rate_unavailable`, naming the pair and the date).
 */
class Pricing
{
    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly Converter $converter,
    ) {}

    /** @return array{net_minor: int, tax_minor: int, total_minor: int, tax_rate: ?string} */
    public function line(Company $company, string $currency, string $qty, int $unitPriceMinor, ?string $taxCodeId, bool $inclusive, DateTimeInterface $date, bool $strict): array
    {
        $amount = Money::ofMinor((string) BigDecimal::of($qty)->multipliedBy($unitPriceMinor)->toScale(0, RoundingMode::HalfUp), $currency);
        $codes = $taxCodeId === null ? [] : [TaxCode::query()->findOrFail($taxCodeId)];
        $at = CarbonImmutable::parse(CarbonImmutable::instance($date)->toDateString(), $company->timezone ?: 'UTC')->setTime(12, 0);

        try {
            $result = $this->tax->forLine($amount, $codes, $inclusive, $at);

            return [
                'net_minor' => (int) $result->net->minor(),
                'tax_minor' => (int) $result->totalTax()->minor(),
                'total_minor' => (int) $result->gross->minor(),
                'tax_rate' => $result->tax[0]->rate ?? null,
            ];
        } catch (TaxRateMissing $e) {
            if ($strict) {
                throw $e;
            }

            return ['net_minor' => (int) $amount->minor(), 'tax_minor' => 0, 'total_minor' => (int) $amount->minor(), 'tax_rate' => null];
        }
    }

    /** The company's rate for $currency → base at $at (identity in the base currency). */
    public function fx(Company $company, string $currency, DateTimeInterface $at): FxSnapshot
    {
        if ($currency === $company->base_currency) {
            return FxSnapshot::identity($currency);
        }

        try {
            return $this->converter->toBase(Money::ofMinor(0, $currency), $company, $at)['snapshot'];
        } catch (RateUnavailable) {
            throw ProcurementError::make('rate_unavailable', [
                'pair' => "{$currency}/{$company->base_currency}",
                'date' => CarbonImmutable::instance($at)->setTimezone($company->timezone ?: 'UTC')->toDateString(),
            ], 'currency');
        }
    }

    /** $minor of $currency in the snapshot's other currency (half up), or as is for an identity snapshot. */
    public static function convert(FxSnapshot $fx, int|string $minor, string $currency): int
    {
        if ($fx->isIdentity()) {
            return (int) $minor;
        }

        return (int) $fx->convert(Money::ofMinor((string) $minor, $currency))->minor();
    }

    /** The end of a calendar day in the company's time zone, as an instant (for "the rate on this date"). */
    public static function endOfDay(Company $company, DateTimeInterface|string $date): CarbonImmutable
    {
        $day = $date instanceof DateTimeInterface ? CarbonImmutable::instance($date)->toDateString() : $date;

        return CarbonImmutable::parse($day, $company->timezone ?: 'UTC')->endOfDay();
    }
}
