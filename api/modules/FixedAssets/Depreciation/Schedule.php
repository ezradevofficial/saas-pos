<?php

namespace Modules\FixedAssets\Depreciation;

use Carbon\CarbonImmutable;
use Modules\FixedAssets\Categories\Categories;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\DepreciationLine;
use Modules\FixedAssets\Models\Disposal;

/**
 * FA-26: an asset's depreciation schedule: what posted runs and its
 * disposal charged, then month by month what is left to its residual
 * value with its current terms (straight line and declining balance;
 * units of production cannot be projected without readings). At most
 * 600 months are projected.
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

        $projectable = $asset->status !== Asset::DISPOSED && $asset->in_service_on !== null
            && in_array($asset->method, [AssetBook::STRAIGHT_LINE, AssetBook::DECLINING_BALANCE], true);

        return ['posted' => $posted, 'projected' => $projectable ? $this->project($asset) : [], 'projectable' => $projectable];
    }

    /** @return list<array<string, mixed>> */
    private function project(Asset $asset): array
    {
        $convention = (string) Categories::settings($asset->company_id)->convention;
        $book = AssetBook::of($asset);
        $from = $asset->depreciated_to === null ? $asset->in_service_on : $asset->depreciated_to->addDay();
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
