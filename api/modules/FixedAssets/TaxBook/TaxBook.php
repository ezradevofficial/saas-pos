<?php

namespace Modules\FixedAssets\TaxBook;

use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Models\Disposal;
use Modules\FixedAssets\Support\FaError;

/**
 * FA-20: the optional tax book. Refused (`tax_rates_unconfirmed`) while a
 * category of the company has no tax class, a row of its class has no
 * confirmed method and rate in the country pack, or the pack's
 * year-of-acquisition rule is unconfirmed. With confirmed data, each
 * asset's yearly allowance, each year at the class row in force at that
 * year's end (review #11): straight line on its base cost, reducing
 * balance on its tax written-down value, in integer minor units, half up
 * (a class with a first-year rate: that rate on cost the year it enters
 * service, then its rate on the residue, straight line);
 * the year it entered service counts as the pack's rule says (a full year,
 * or its months in service over 12). In the year of its disposal an asset
 * takes no allowance and its row says so, with the base proceeds; the
 * balancing allowance or charge is not computed until the pack carries
 * the rule (owner's list). Never posted.
 */
class TaxBook
{
    /** The label of the year-of-acquisition rule in `unconfirmed` lists. */
    public const ACQUISITION_RULE = 'acquisition_rule';

    public function __construct(private readonly TaxClasses $classes) {}

    /** @return list<array{category: ?string, tax_class: ?string}> what cannot be computed from: categories and their classes, then the acquisition rule */
    public function unconfirmed(Company $company): array
    {
        $missing = [];

        foreach (Category::query()->where('company_id', $company->id)->whereNull('archived_at')->orderBy('code')->get() as $category) {
            if ($category->tax_class === null || ! $this->classes->keyConfirmed((string) $company->country, $category->tax_class)) {
                $missing[] = ['category' => (string) $category->code, 'tax_class' => $category->tax_class];
            }
        }

        if ($this->classes->acquisitionRule((string) $company->country) === null) {
            $missing[] = ['category' => null, 'tax_class' => self::ACQUISITION_RULE];
        }

        return $missing;
    }

    /** 422 `tax_rates_unconfirmed` naming the classes, unless every category's class and the acquisition rule are confirmed. */
    public function assertConfirmed(Company $company, string $field = 'tax_book'): void
    {
        $missing = $this->unconfirmed($company);

        if ($missing !== [] || Category::query()->where('company_id', $company->id)->whereNull('archived_at')->doesntExist()) {
            throw FaError::make('tax_rates_unconfirmed', ['classes' => self::names($missing)], $field);
        }
    }

    /** @param list<array{category: ?string, tax_class: ?string}> $missing */
    public static function names(array $missing): string
    {
        $names = implode(', ', array_map(fn (array $row) => $row['tax_class'] === self::ACQUISITION_RULE
            ? __('fixed_assets.tax_classes.'.self::ACQUISITION_RULE) : ($row['tax_class'] ?? $row['category']), $missing));

        return $names === '' ? '-' : $names;
    }

    /**
     * The tax book of $year: per active asset in service by the year's end,
     * or one disposed of during it, the value at the year's start, the
     * allowance and the value at its end (base minor units).
     *
     * @return list<array{asset_id: string, number: string, name: string, tax_class: string, opening: int, allowance: int, closing: int, disposed: bool, proceeds: ?int}>
     */
    public function year(Company $company, int $year): array
    {
        $this->assertConfirmed($company, 'year');
        $country = (string) $company->country;
        $rule = (string) $this->classes->acquisitionRule($country);
        $end = CarbonImmutable::create($year, 12, 31);
        $rows = [];
        $categories = Category::query()->where('company_id', $company->id)->get()->keyBy('id');

        $assets = Asset::query()->where('company_id', $company->id)->whereIn('status', [Asset::ACTIVE, Asset::DISPOSED])
            ->whereNotNull('in_service_on')->where('in_service_on', '<=', $end->toDateString())->orderBy('number')->get();

        foreach ($assets as $asset) {
            $key = $categories->get($asset->category_id)?->tax_class;

            if ($key === null || ! $this->classes->keyConfirmed($country, (string) $key) || ($asset->disposed_on !== null && $asset->disposed_on->year < $year)) {
                continue;
            }

            $cost = (int) $asset->base_cost_minor;
            $value = $cost;
            $first = $asset->in_service_on->year;
            // KE Second Schedule: after a first-year rate, the residue (cost less the first allowance) is written off.
            $residue = null;

            for ($y = $first; $y <= $year; $y++) {
                $disposed = $asset->disposed_on !== null && $asset->disposed_on->year === $y;
                $class = $this->classes->find($country, (string) $key, sprintf('%04d-12-31', $y));

                if (! TaxClasses::confirmed($class)) {
                    throw FaError::make('tax_rates_unconfirmed', ['classes' => "{$key} ({$y})"], 'year');
                }

                $share = $y === $first && $rule === 'pro_rata_months' ? 13 - $asset->in_service_on->month : 12;
                if ($y === $first && ($class['first_year_rate'] ?? null) !== null) {
                    $allowance = $disposed ? 0 : $this->allowance(['method' => 'straight_line', 'rate' => $class['first_year_rate']], $cost, $value, $share);
                    $residue = $cost - $allowance;
                } else {
                    $allowance = $disposed ? 0 : $this->allowance($class, $residue ?? $cost, $value, $share);
                }

                if ($y === $year) {
                    $proceeds = $disposed ? Disposal::query()->where('asset_id', $asset->id)->where('status', 'posted')->value('base_proceeds_minor') : null;
                    $rows[] = ['asset_id' => $asset->id, 'number' => $asset->number, 'name' => $asset->name, 'tax_class' => (string) $key,
                        'opening' => $value, 'allowance' => $allowance, 'closing' => $value - $allowance, 'disposed' => $disposed,
                        'proceeds' => $proceeds === null ? null : (int) $proceeds];
                }

                $value -= $allowance;
            }
        }

        return $rows;
    }

    /** The class's annual allowance for $months of 12, half up, never more than $value. */
    private function allowance(array $class, int $cost, int $value, int $months): int
    {
        if ($value <= 0) {
            return 0;
        }

        $rate = BigDecimal::of((string) $class['rate'])->withPointMovedRight(4)->toScale(0, RoundingMode::HalfUp)->toBigInteger();
        $base = $class['method'] === 'straight_line' ? $cost : $value;
        $exact = BigInteger::of($base)->multipliedBy($rate)->multipliedBy($months);
        $denominator = BigInteger::of(1_000_000 * 12);
        $amount = $exact->multipliedBy(2)->plus($denominator)->quotient($denominator->multipliedBy(2))->toInt();

        return min($value, $amount);
    }
}
