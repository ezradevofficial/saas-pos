<?php

namespace Modules\FuelStation\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Models\Voucher;

/**
 * FUEL-21, NFR-04: the company's active vouchers with a balance (code,
 * balance, currency, fuel type, expiry), so the till can check a voucher
 * offline. The server checks again when the sale arrives (VoucherTender) and
 * wins: an offline overdraw is flagged, not refused.
 */
class VoucherSource implements SnapshotSource
{
    public function key(): string
    {
        return 'fuel_vouchers';
    }

    public function module(): string
    {
        return FuelStationServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        if (! Station::query()->where('location_id', $scope->location->id)->whereNull('archived_at')->exists()) {
            return [];
        }

        return Voucher::query()->where('company_id', $scope->companyId())->where('status', Voucher::ACTIVE)->where('balance_minor', '>', 0)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $scope->at->toDateString()))
            ->orderBy('code')->orderBy('id')->get()->map(fn (Voucher $v) => [
                'id' => $v->id,
                'code' => $v->code,
                'currency' => $v->currency,
                'balance_minor' => (string) $v->balance_minor,
                'fuel_type' => $v->fuel_type,
                'expires_on' => $v->expires_on?->toDateString(),
            ])->all();
    }
}
