<?php

namespace Modules\FixedAssets\Depreciation;

use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Categories\Categories;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Models\DepreciationLine;
use Modules\FixedAssets\Models\Disposal;

/**
 * FA-26: an asset's depreciation schedule: what posted runs and its
 * disposal charged, then month by month what is left to its residual
 * value with its current terms (straight line and declining balance;
 * units of production cannot be projected without readings). A draft
 * projects from its acquisition date until it is put in service (the
 * walkthrough, stage 7D closing). At most 600 months are projected.
 */
class Schedule
{
    private const MAX_MONTHS = 600;

    public function __construct(private readonly Calculator $calculator) {}

    /** @return array{posted: list<array<string, mixed>>, projected: list<array<string, mixed>>, projectable: bool} */
    public function of(Asset $asset): array
    {
        $posted = DepreciationLine::query()->where('asset_id', $asset->id)
            ->whereIn('run_id', fn ($q) => $q->select('id')->from('fa_depreciation_runs')->where('status', 'posted'))
            ->orderBy('from_date')->get()
            ->map(fn (DepreciationLine $line) => [
                'run_id' => $line->run_id,
                'from' => $line->from_date->toDateString(),
                'to' => $line->to_date->toDateString(),
                'amount' => (string) $line->amount_minor,
                'units' => $line->units === null ? null : (string) $line->units,
                'accumulated' => (string) $line->accumulated_after_minor,
                'book_value' => (string) $line->book_value_after_minor,
            ])->all();

        $disposal = Disposal::query()->where('asset_id', $asset->id)->where('status', 'posted')->first();

        if ($disposal !== null && (int) $disposal->depreciation_minor > 0) {
            $posted[] = ['run_id' => null, 'from' => null, 'to' => $disposal->disposal_date->toDateString(), 'amount' => (string) $disposal->depreciation_minor,
                'units' => null, 'accumulated' => (string) $disposal->accumulated_minor, 'book_value' => (string) $disposal->book_value_minor];
        }

        $book = $asset->status === Asset::DRAFT ? self::drafted($asset) : $asset;
        $projectable = $book !== null && $asset->status !== Asset::DISPOSED && ($book->in_service_on ?? $book->acquired_on) !== null
            && in_array($asset->method, [AssetBook::STRAIGHT_LINE, AssetBook::DECLINING_BALANCE], true) && (int) $book->book_cost_minor > 0;

        return ['posted' => $posted, 'projected' => $projectable ? $this->project($book) : [], 'projectable' => $projectable];
    }

    /**
     * A draft as it would be put in service (never saved): its cost as the book
     * cost and its category's residual share, when its cost is in the company's
     * base currency (another currency needs the rate fixed at activation).
     */
    private static function drafted(Asset $asset): ?Asset
    {
        $company = Company::query()->find($asset->company_id);
        $category = $asset->category_id === null ? null : Category::query()->find($asset->category_id);

        if ($company === null || $asset->acquired_on === null || (string) $asset->currency !== (string) $company->base_currency) {
            return null;
        }

        $cost = (int) $asset->cost_minor;
        $residual = (int) $asset->residual_minor;

        if ($residual === 0 && $category !== null && BigDecimal::of((string) $category->residual_percent)->isPositive()) {
            $residual = BigDecimal::of($cost)->multipliedBy((string) $category->residual_percent)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
        }

        $book = $asset->replicate();
        $book->forceFill(['company_id' => $asset->company_id, 'book_cost_minor' => $cost, 'residual_minor' => $residual, 'accumulated_minor' => 0, 'impairment_minor' => 0, 'depreciated_to' => null]);

        return $book;
    }

    /** @return list<array<string, mixed>> */
    private function project(Asset $asset): array
    {
        $convention = (string) Categories::settings($asset->company_id)->convention;
        $book = AssetBook::of($asset);
        $from = $asset->depreciated_to === null ? CarbonImmutable::parse($asset->in_service_on ?? $asset->acquired_on)->startOfDay() : $asset->depreciated_to->addDay();
        $rows = [];

        for ($i = 0; $i < self::MAX_MONTHS && $book->depreciable() > 0; $i++) {
            $to = CarbonImmutable::parse($from)->endOfMonth()->startOfDay();
            $charge = $this->calculator->charge($book, $from, $to, $convention)->amount;
            $book = $book->after($charge);

            if ($charge > 0) {
                $rows[] = ['run_id' => null, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'amount' => (string) $charge, 'units' => null,
                    'accumulated' => (string) $book->accumulated, 'book_value' => (string) $book->bookValue()];
            }

            $from = $to->addDay();
        }

        return $rows;
    }
}
