<?php

namespace App\Core\Sync\Sources;

use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * MD-03, CP-02, NFR-04: the device's company's active tax codes with every
 * effective-dated rate (dates inclusive, in the company's time zone).
 * `rate` is a percentage string ("16.0000"), null with `needs_confirmation`
 * when the rate is needed: the till refuses to sell under such a rate,
 * never guesses it. Offline, the till picks the rate in force on the sale's
 * local date, so a dated change applies without a connection.
 */
class TaxCodeSource implements SnapshotSource
{
    public function key(): string
    {
        return 'tax_codes';
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
        return TaxCode::query()
            ->where('company_id', $scope->companyId())
            ->whereNull('archived_at')
            ->with('rates')
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->map(fn (TaxCode $code) => [
                'id' => $code->id,
                'code' => $code->code,
                'name' => $code->name,
                'kind' => $code->kind,
                'fiscal_code' => $code->fiscal_code,
                'rates' => $code->rates->map(fn (TaxRate $rate) => [
                    'rate' => $rate->rate,
                    'effective_from' => $rate->effective_from->toDateString(),
                    'effective_to' => $rate->effective_to?->toDateString(),
                    'needs_confirmation' => $rate->isNeeded(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
