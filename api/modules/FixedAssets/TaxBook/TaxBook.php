<?php

namespace Modules\FixedAssets\TaxBook;

use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Support\FaError;

/**
 * FA-20: the optional tax book. Refused (`tax_rates_unconfirmed`) while a
 * category of the company has no tax class or its class has no confirmed
 * method and rate in the country pack. With confirmed data, each asset's
 * yearly allowance: straight line on its base cost (the remainder in the
 * last year), reducing balance on its tax written-down value, in integer
 * minor units, half up, a full year in the year it entered service. Never
 * posted.
 */
class TaxBook
{
    public function __construct(private readonly TaxClasses $classes) {}

    /** @return list<array{category: string, tax_class: ?string}> categories whose class cannot be computed from */
    public function unconfirmed(Company $company): array
    {
        $missing = [];

        foreach (Category::query()->where('company_id', $company->id)->whereNull('archived_at')->orderBy('code')->get() as $category) {
            $class = $category->tax_class === null ? null : $this->classes->find((string) $company->country, $category->tax_class);

            if (! TaxClasses::confirmed($class)) {
                $missing[] = ['category' => (string) $category->code, 'tax_class' => $category->tax_class];
            }
        }

        return $missing;
    }

    /** 422 `tax_rates_unconfirmed` naming the classes, unless every category's class is confirmed. */
    public function assertConfirmed(Company $company, string $field = 'tax_book'): void
    {
        $missing = $this->unconfirmed($company);

        if ($missing !== [] || Category::query()->where('company_id', $company->id)->whereNull('archived_at')->doesntExist()) {
            $names = implode(', ', array_map(fn (array $row) => $row['tax_class'] ?? $row['category'], $missing));

            throw FaError::make('tax_rates_unconfirmed', ['classes' => $names === '' ? '-' : $names], $field);
        }
    }

    /**
     * The tax book of $year: per active or disposed asset in service by the
     * year's end, the value at the year's start, the allowance and the value
     * at its end (base minor units).
     *
     * @return list<array{asset_id: string, number: string, name: string, tax_class: string, opening: int, allowance: int, closing: int}>
     */
    public function year(Company $company, int $year): array
    {
        $this->assertConfirmed($company, 'year');
        $end = CarbonImmutable::create($year, 12, 31);
        $rows = [];
        $categories = Category::query()->where('company_id', $company->id)->get()->keyBy('id');

        $assets = Asset::query()->where('company_id', $company->id)->whereIn('status', [Asset::ACTIVE, Asset::DISPOSED])
            ->whereNotNull('in_service_on')->where('in_service_on', '<=', $end->toDateString())->orderBy('number')->get();

        foreach ($assets as $asset) {
            $category = $categories->get($asset->category_id);
            $class = $category?->tax_class === null ? null : $this->classes->find((string) $company->country, (string) $category->tax_class);

            if (! TaxClasses::confirmed($class) || ($asset->disposed_on !== null && $asset->disposed_on->year < $year)) {
                continue;
            }

            $cost = (int) $asset->base_cost_minor;
            $value = $cost;
            $first = $asset->in_service_on->year;

            for ($y = $first; $y <= $year; $y++) {
                $allowance = $this->allowance($class, $cost, $value);

                if ($y === $year) {
                    $rows[] = ['asset_id' => $asset->id, 'number' => $asset->number, 'name' => $asset->name, 'tax_class' => (string) $category->tax_class,
                        'opening' => $value, 'allowance' => $allowance, 'closing' => $value - $allowance];
                }

                $value -= $allowance;
            }
        }

        return $rows;
    }

    private function allowance(array $class, int $cost, int $value): int
    {
        if ($value <= 0) {
            return 0;
        }

        $rate = BigDecimal::of((string) $class['rate'])->withPointMovedRight(4)->toScale(0, RoundingMode::HalfUp)->toBigInteger();
        $base = $class['method'] === 'straight_line' ? $cost : $value;
        $exact = BigInteger::of($base)->multipliedBy($rate);
        $denominator = BigInteger::of(1_000_000);
        $amount = $exact->multipliedBy(2)->plus($denominator)->quotient($denominator->multipliedBy(2))->toInt();

        return min($value, $amount);
    }
}
