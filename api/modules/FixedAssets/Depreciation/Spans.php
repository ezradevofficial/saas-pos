<?php

namespace Modules\FixedAssets\Depreciation;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Reading;
use Modules\FixedAssets\Support\Decimals;

/**
 * FA-09, FA-12, FA-17: an asset's next charge up to a day: its span starts
 * the day after its depreciated-to date (or on its in-service date), and a
 * units-of-production asset counts the meter over the span (the last
 * reading on or before each end, else the meter at acquisition).
 */
class Spans
{
    public function __construct(private readonly Calculator $calculator) {}

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, charge: Charge}|null null when the span is empty */
    public function next(Asset $asset, CarbonImmutable $to, string $convention, bool $disposal = false): ?array
    {
        if ($asset->in_service_on === null) {
            return null;
        }

        $from = $asset->depreciated_to === null ? $asset->in_service_on : $asset->depreciated_to->addDay();

        if ($from > $to) {
            return null;
        }

        $book = AssetBook::of($asset);
        $used = $before = null;

        if ($book->method === AssetBook::UNITS_OF_PRODUCTION) {
            $start = self::meter($asset, $from->subDay());
            $used = Decimals::show(BigDecimal::of(self::meter($asset, $to))->minus($start));
            $before = Decimals::show(BigDecimal::of($start)->minus((string) $asset->meter_start));
        }

        return ['from' => $from, 'to' => $to, 'charge' => $this->calculator->charge($book, $from, $to, $convention, $disposal, $used, $before)];
    }

    /** The meter of $asset on $day: its last reading on or before it, else the meter at acquisition. */
    public static function meter(Asset $asset, CarbonImmutable $day): string
    {
        $reading = Reading::query()->where('asset_id', $asset->id)->where('read_on', '<=', $day->toDateString())
            ->orderByDesc('read_on')->orderByDesc('reading')->value('reading');

        return (string) ($reading ?? $asset->meter_start ?? '0');
    }
}
