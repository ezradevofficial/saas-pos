<?php

namespace App\Core\Sync\Sources;

use App\Core\Currency\ExchangeRates;
use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\Rate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * CUR-03, CUR-09, NFR-04: the rates a till converts with offline, for the
 * device's company and the tenant's active currencies: the rate in force
 * for each pair now (`current: true`, chosen as ExchangeRates does: the
 * latest shop rate, else the latest reference rate), and every rate
 * already entered for a later time, so a scheduled rate applies offline.
 *
 * Rates are as stored: 1 `base` = `mid` `quote` (8 decimals), with `buy`
 * and `sell` when entered. Offline the device picks, for a pair (either
 * direction) at the sale's instant, the latest `shop` row with
 * effective_at at or before it, else the latest `reference` row, and
 * records that row's id and values on the sale (CUR-04, CUR-09).
 */
class ExchangeRateSource implements SnapshotSource
{
    public function __construct(private readonly ExchangeRates $rates) {}

    public function key(): string
    {
        return 'exchange_rates';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $rows = [];

        foreach ($this->rates->all($scope->company, $scope->at) as $rate) {
            $rows[] = $this->row($rate->id, $rate->base, $rate->quote, $rate->kind, $rate->mid, $rate->buy, $rate->sell, $rate->effectiveAt, true);
        }

        $active = TenantCurrency::query()->where('active', true)->pluck('code')->all();

        ExchangeRate::query()
            ->where('company_id', $scope->companyId())
            ->where('effective_at', '>', $scope->at->format('Y-m-d H:i:s.uP'))
            ->whereIn('base', $active)
            ->whereIn('quote', $active)
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get()
            ->each(function (ExchangeRate $rate) use (&$rows) {
                $rows[] = $this->row($rate->id, $rate->base, $rate->quote, $rate->kind, Rate::normalise($rate->mid),
                    $rate->buy === null ? null : Rate::normalise($rate->buy), $rate->sell === null ? null : Rate::normalise($rate->sell),
                    $rate->effective_at, false);
            });

        return $rows;
    }

    /** @return array<string, mixed> */
    private function row(?string $id, string $base, string $quote, string $kind, string $mid, ?string $buy, ?string $sell, mixed $effectiveAt, bool $current): array
    {
        return [
            'id' => $id,
            'base' => $base,
            'quote' => $quote,
            'kind' => $kind,
            'mid' => $mid,
            'buy' => $buy,
            'sell' => $sell,
            'effective_at' => Iso::of($effectiveAt),
            'current' => $current,
        ];
    }
}
